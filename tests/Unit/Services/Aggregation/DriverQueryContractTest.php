<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\DriverQueryContract;
use PHPUnit\Framework\TestCase;

final class DriverQueryContractTest extends TestCase
{
    private function profiles(): array
    {
        return [['query_contract' => [
            'version' => 1, 'default_filters' => ['dimensions.scope' => 'traffic'],
            'scope_filter' => 'dimensions.scope', 'scopes' => ['traffic' => ['sessions', 'bounceRate'], 'users' => ['activeUsers'], '__unscoped__' => ['sessions']],
            'blocked_metrics' => ['spend'], 'aliases' => ['bounce_rate' => 'bounceRate'],
            'rules' => ['bounceRate' => ['reducer' => 'companion_weighted_average', 'weight_names' => ['sessions']], 'activeUsers' => ['reducer' => 'sum', 'quality' => 'not_window_unique']],
        ]]];
    }

    public function testDefaultsSelectOneScopeAndNormalizeRawAndCanonicalCase(): void
    {
        foreach (['bounce_rate', 'bounceRate', 'BOUNCERATE'] as $name) {
            $prepared = (new DriverQueryContract())->prepare($this->profiles(), ['rate' => $name], [], []);
            self::assertSame('traffic', $prepared['filters']['dimensions.scope']);
            self::assertSame(['bounceRate'], $prepared['contract']['resolved_rules']['rate']['source_names']);
            self::assertSame('companion_weighted_average', $prepared['contract']['resolved_rules']['rate']['reducer']);
        }
    }

    public function testExplicitUnscopedAndUserQualityArePreserved(): void
    {
        $policy = new DriverQueryContract();
        $baseline = $policy->prepare($this->profiles(), ['sessions' => 'sessions'], ['dimensions.scope' => ['operator' => 'is_null']], []);
        self::assertSame('is_null', $baseline['filters']['dimensions.scope']['operator']);
        $users = $policy->prepare($this->profiles(), ['users' => 'activeUsers'], ['dimensions.scope' => 'users'], []);
        self::assertSame('not_window_unique', $users['contract']['quality']['users']);
    }

    public function testUnsafeRequestsCannotReachLegacyExecution(): void
    {
        foreach ([
            [['cost' => 'spend'], [], []],
            [['mixed' => 'activeUsers'], [], []],
            [['raw' => 'SUM(value)'], [], []],
            [['sessions' => 'sessions'], ['dimensions.scope' => ['operator' => 'in', 'value' => ['traffic', 'users']]], []],
            [['sessions' => 'sessions'], [], ['trailing_weekly']],
        ] as [$aggregations, $filters, $groups]) {
            try {
                (new DriverQueryContract())->prepare($this->profiles(), $aggregations, $filters, $groups);
                self::fail('Unsafe request was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testChannelsWithoutContractRetainExistingBehavior(): void
    {
        self::assertSame(['contract' => [], 'filters' => []], (new DriverQueryContract())->prepare([], ['value' => 'anything'], [], []));
    }

    public function testKpiInputsCannotCoerceMissingWeightsIntoZero(): void
    {
        $policy = new DriverQueryContract();
        $contract = $policy->prepare($this->profiles(), ['rate' => 'bounceRate'], [], [])['contract'];
        self::assertSame([], $policy->unavailableMetrics($contract, [['rate' => 0.0]]));
        $meta = ['unavailable_metrics' => $policy->unavailableMetrics($contract, [['rate' => null]])];
        self::assertSame(['rate'], $meta['unavailable_metrics']);
        $this->expectException(\InvalidArgumentException::class);
        \Services\Analytics\VirtualMetricEngine\AstDataHydrator::assertInputsAvailable($meta);
    }
}
