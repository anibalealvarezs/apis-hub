<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Services\Http\RequestBudgetLimiter;

class RequestBudgetLimiterTest extends TestCase
{
    private Client $redis;
    private string $namespace;
    private RequestBudgetLimiter $limiter;

    protected function setUp(): void
    {
        if (!getenv('TEST_RESOURCE_GUARD_REDIS_PORT')) self::markTestSkipped('Set TEST_RESOURCE_GUARD_REDIS_PORT to an isolated test Redis');
        $this->redis = new Client(['host' => '127.0.0.1', 'port' => (int)getenv('TEST_RESOURCE_GUARD_REDIS_PORT'), 'timeout' => 1]);
        $this->redis->ping();
        $this->namespace = 'resource_guard_test_'.bin2hex(random_bytes(8));
        $this->limiter = new RequestBudgetLimiter($this->redis, $this->namespace);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->namespace.':*');
            if ($keys) $this->redis->del($keys);
        }
    }

    private function policy(array $overrides = []): array
    {
        return $overrides + ['group' => 'data', 'cost' => 1, 'tenant_rate' => 60, 'tenant_burst' => 100,
            'actor_rate' => 60, 'actor_burst' => 100, 'concurrency' => 2, 'lease_seconds' => 10];
    }

    public function testConcurrencyIsSharedAndDeniedCallsDoNotConsumeBudget(): void
    {
        $policy = $this->policy();
        $first = $this->limiter->acquire($policy, 'first');
        $second = $this->limiter->acquire($policy, 'second');
        $third = $this->limiter->acquire($policy, 'third');
        self::assertTrue($first['allowed']);
        self::assertTrue($second['allowed']);
        self::assertFalse($third['allowed']);
        self::assertSame('concurrency', $third['reason']);
        self::assertFalse((bool)$this->redis->exists($this->namespace.':{data}:actor:'.hash('sha256', 'third')));
        $this->limiter->release($first['lease']);
        self::assertTrue($this->limiter->acquire($policy, 'third')['allowed']);
        self::assertSame('1', $this->redis->hget($this->namespace.':{data}:stats', 'concurrency_rejected'));
    }

    public function testTenantAndActorBudgetsAreBothEnforced(): void
    {
        $policy = $this->policy(['tenant_burst' => 2, 'actor_burst' => 1, 'concurrency' => 0]);
        self::assertTrue($this->limiter->acquire($policy, 'first')['allowed']);
        self::assertFalse($this->limiter->acquire($policy, 'first')['allowed']);
        self::assertTrue($this->limiter->acquire($policy, 'second')['allowed']);
        $blocked = $this->limiter->acquire($policy, 'third');
        self::assertFalse($blocked['allowed']);
        self::assertSame('budget', $blocked['reason']);
        self::assertGreaterThanOrEqual(1, $blocked['retry_after']);
    }

    public function testCrashedRequestsExpireAndGroupsCannotStarveOneAnother(): void
    {
        $policy = $this->policy(['concurrency' => 1, 'lease_seconds' => 1]);
        $this->limiter->acquire($policy, 'first');
        self::assertFalse($this->limiter->acquire($policy, 'second')['allowed']);
        self::assertTrue($this->limiter->acquire($this->policy(['group' => 'orchestration', 'concurrency' => 1]), 'first')['allowed']);
        usleep(1100000);
        self::assertTrue($this->limiter->acquire($policy, 'second')['allowed']);
    }
}
