<?php

namespace Tests\Unit\Services\Analytics;

use PHPUnit\Framework\TestCase;
use Services\Analytics\ScopedReportStore;

final class ScopedReportStoreTest extends TestCase
{
    private string $directory;
    private int $now = 10000;
    private ScopedReportStore $store;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/analytics-report-test-'.bin2hex(random_bytes(8));
        $this->store = new ScopedReportStore($this->directory, fn () => $this->now);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
    private function request(int $account = 1): array { return ['operation' => 'report', 'channel' => 'fixture', 'account' => $account, 'authorization_scope' => 'actor']; }

    public function testExplicitRefreshCannotBypassTheMinimumCatalogAge(): void
    {
        $key = hash('sha256', 'catalog-refresh');
        $request = array_replace($this->request(), ['operation' => 'catalog']);
        $this->store->enqueue($key, $request, 86400);
        $this->store->process(fn () => ['catalog' => ['revision' => 'first']]);
        $this->store->expireReady($key);
        self::assertSame('ready', $this->store->enqueue($key, $request)['state']);
        $this->now += 300;
        $this->store->expireReady($key);
        $pending = $this->store->enqueue($key, $request);
        self::assertSame('pending', $pending['state']);
        self::assertSame('first', $pending['last_good']['catalog']['revision']);
    }

    public function testReadsCoalesceAndFairWorkerProcessesOneRequestPerProperty(): void
    {
        $a = hash('sha256', 'a'); $b = hash('sha256', 'b'); $c = hash('sha256', 'c');
        $this->store->enqueue($a, $this->request());
        $this->store->enqueue($a, $this->request());
        $this->store->enqueue($b, $this->request());
        $this->store->enqueue($c, $this->request(2));
        self::assertCount(3, $this->store->records());
        $calls = 0;
        self::assertCount(2, $this->store->process(function () use (&$calls) { $calls++; return ['rows' => [['users' => 3]]]; }, 3));
        self::assertSame(2, $calls);
        self::assertSame('ready', $this->store->get($a)['state']);
        self::assertSame('pending', $this->store->get($b)['state']);
        self::assertSame('ready', $this->store->enqueue($a, $this->request())['state']);
    }

    public function testExpiredDataStaysExplicitlyStaleWhenRefreshFails(): void
    {
        $key = hash('sha256', 'stale');
        $this->store->enqueue($key, $this->request(), 30);
        $this->store->process(fn () => ['users' => 3]);
        $this->now += 31;
        $pending = $this->store->enqueue($key, $this->request());
        self::assertSame('pending', $pending['state']);
        self::assertSame(['users' => 3], $pending['last_good']);
        $this->store->process(fn () => throw new \RuntimeException('secret upstream detail'));
        $failed = $this->store->get($key);
        self::assertSame('failed', $failed['state']);
        self::assertSame(['users' => 3], $failed['last_good']);
        self::assertStringNotContainsString('secret', $failed['error']);
        self::assertSame('failed', $this->store->enqueue($key, $this->request())['state']);
    }

    public function testPerActorQueueBudgetAndUnsafePathsAreRejected(): void
    {
        for ($i = 0; $i < 10; $i++) $this->store->enqueue(hash('sha256', 'queued-'.$i), $this->request($i + 1));
        try { $this->store->enqueue(hash('sha256', 'overflow'), $this->request()); self::fail('Queue budget ignored.'); }
        catch (\OverflowException $e) { self::assertStringContainsString('budget', $e->getMessage()); }
        $this->expectException(\InvalidArgumentException::class);
        $this->store->get('../outside');
    }

    public function testQuotaFeedbackDefersSourceWorkButLeavesCompletedResultsReadable(): void
    {
        $a = hash('sha256', 'quota-a'); $b = hash('sha256', 'quota-b');
        $this->store->enqueue($a, $this->request()); $this->store->enqueue($b, $this->request());
        $this->store->process(fn () => ['source_delay_seconds' => 300, 'users' => 5]);
        self::assertSame([], $this->store->process(fn () => self::fail('Quota cooldown ignored.')));
        self::assertSame('ready', $this->store->get($a)['state']);
        $this->now += 301;
        self::assertSame([$b], $this->store->process(fn () => ['users' => 6]));
    }
}
