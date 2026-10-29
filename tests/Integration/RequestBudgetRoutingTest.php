<?php

namespace Tests\Integration;

use Classes\RoutingCore;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\ClientInterface;
use Services\Http\RequestBudgetLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestBudgetRoutingTest extends TestCase
{
    private Client $redis;
    private mixed $previousRedis;
    private string $namespace;

    protected function setUp(): void
    {
        if (!getenv('TEST_RESOURCE_GUARD_REDIS_PORT')) self::markTestSkipped('Requires isolated test Redis');
        $this->redis = new Client(['host' => '127.0.0.1', 'port' => (int)getenv('TEST_RESOURCE_GUARD_REDIS_PORT'), 'timeout' => 1]);
        $property = new \ReflectionProperty(Helpers::class, 'redisClient');
        $this->previousRedis = $property->getValue();
        $property->setValue(null, $this->redis);
        $this->namespace = 'routing_guard_test_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            (new \ReflectionProperty(Helpers::class, 'redisClient'))->setValue(null, $this->previousRedis);
            $keys = $this->redis->keys($this->namespace.':*');
            if ($keys) $this->redis->del($keys);
        }
    }

    public function testControllerFailureReleasesTheLeaseAndHealthRemainsAvailableDuringRedisFailure(): void
    {
        $core = new RoutingCore(new RequestBudgetLimiter($this->redis, $this->namespace));
        $core->map('/test/metric/aggregate', 'POST', static function (...$args) { throw new \RuntimeException('Test controller failure'); }, public: true);
        $response = $core->handle(Request::create('/test/metric/aggregate', 'POST'));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $this->redis->zcard($this->namespace.':{data}:active'));

        $broken = $this->createMock(ClientInterface::class);
        $broken->method('__call')->willThrowException(new \RuntimeException('Isolated Redis outage'));
        (new \ReflectionProperty(Helpers::class, 'redisClient'))->setValue(null, $broken);
        $core = new RoutingCore(new RequestBudgetLimiter($broken, $this->namespace));
        $core->map('/test/metric/aggregate', 'POST', static fn (...$args) => new Response('should not run'), public: true);
        $core->map('/api/heartbeat', 'GET', static fn (...$args) => new Response('healthy'), public: true);
        $response = $core->handle(Request::create('/test/metric/aggregate', 'POST'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame(200, $core->handle(Request::create('/api/heartbeat'))->getStatusCode());
    }

    public function testSaturationReturns429BeforeInvokingTheController(): void
    {
        $limiter = new RequestBudgetLimiter($this->redis, $this->namespace);
        $request = Request::create('/test/metric/aggregate', 'POST');
        $policy = \Services\Http\RequestBudgetPolicy::forRequest($request);
        $leases = [];
        try {
            for ($index = 0; $index < $policy['concurrency']; $index++) {
                $decision = $limiter->acquire($policy, 'holder-'.$index);
                self::assertTrue($decision['allowed']);
                $leases[] = $decision['lease'];
            }
            $core = new RoutingCore($limiter);
            $core->map('/test/metric/aggregate', 'POST', static function (...$args) {
                self::fail('Saturated requests must not run their controller');
            }, public: true);
            $response = $core->handle($request);
            self::assertSame(429, $response->getStatusCode());
            self::assertSame('1', $response->headers->get('Retry-After'));
        } finally {
            foreach ($leases as $lease) $limiter->release($lease);
        }
    }
}
