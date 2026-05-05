<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
use Services\Aggregation\AggregationProfileResolver;
use Tests\Unit\BaseUnitTestCase;

final class AggregationProfileResolverTest extends BaseUnitTestCase
{
    public function testUsesInjectedProfilesResolverWhenProvided(): void
    {
        $resolver = new AggregationProfileResolver(
            aggregationProfilesResolver: static fn (string $channel): array => $channel === 'demo' ? [['key' => 'demo_profile']] : [],
        );

        $profiles = $resolver->resolve('demo');

        $this->assertCount(1, $profiles);
        $this->assertSame('demo_profile', $profiles[0]['key']);
    }

    public function testResolvesProfilesFromInjectedRegistryAndNormalizesContract(): void
    {
        $resolver = new AggregationProfileResolver(
            aggregationProfilesResolver: null,
            driverRegistryResolver: static fn (): array => [
                'demo_channel' => [
                    'driver' => FakeAggregationProfileDriver::class,
                ],
            ],
        );

        $profiles = $resolver->resolve('demo_channel');

        $this->assertCount(1, $profiles);
        $this->assertSame('demo_profile', $profiles[0]['key']);
        $this->assertSame('demo_channel', $profiles[0]['channel']);
        $this->assertSame(['eq', 'neq'], $profiles[0]['filter_contract']['channel']);
    }

    public function testReturnsEmptyWhenDriverDoesNotPublishProfileContract(): void
    {
        $resolver = new AggregationProfileResolver(
            aggregationProfilesResolver: null,
            driverRegistryResolver: static fn (): array => [
                'demo_channel' => [
                    'driver' => \stdClass::class,
                ],
            ],
        );

        $this->assertSame([], $resolver->resolve('demo_channel'));
    }
}

final class FakeAggregationProfileDriver implements AggregationProfileProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getAggregationProfiles(): array
    {
        return [[
            'key' => 'demo_profile',
            'group_patterns' => [['daily']],
            'filter_contract' => [
                'channel' => ['=', '!='],
            ],
            'reducer_strategies' => [
                '*' => 'sum',
            ],
        ]];
    }
}

