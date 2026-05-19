<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
use Services\Aggregation\CanonicalMetricSqlResolver;
use Tests\Unit\BaseUnitTestCase;

final class CanonicalMetricSqlResolverTest extends BaseUnitTestCase
{
    public function testResolvesLegacyAliasIntoCanonicalMarketingExpression(): void
    {
        $resolver = new CanonicalMetricSqlResolver();

        $expression = $resolver->resolveMarketingMetricExpression(
            requestedMetric: 'cost_per_result',
            channel: 'facebook_marketing',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertNotNull($expression);
        $this->assertStringContainsString("'results'", (string)$expression);
        $this->assertStringContainsString("'results_daily'", (string)$expression);
        $this->assertStringContainsString("'spend'", (string)$expression);
    }

    public function testResolvesProviderSpecificActionMetricWhenDeclaredInDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver();

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'actions',
            channel: 'facebook_marketing',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('deprecated_legacy_metric', $resolved['input_type']);
        $this->assertNull($resolved['canonical_metric']);
        $this->assertSame('ambiguous_metric_alias', $resolved['deprecation']['reason']);
        $this->assertNotNull($resolved['sql_expression']);
        $this->assertStringContainsString("'actions'", (string)$resolved['sql_expression']);
        $this->assertStringContainsString("'actions_daily'", (string)$resolved['sql_expression']);
    }

    public function testUsesOverrideDictionaryBeforeDefaultDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [
                'aggregation' => [
                    'metric_equivalences' => [
                        'marketing_hierarchy' => [
                            'facebook_marketing' => [
                                'conversions' => ['lead', 'lead_daily'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $expression = $resolver->resolveMarketingMetricExpression(
            requestedMetric: 'conversions',
            channel: 'facebook_marketing',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertNotNull($expression);
        $this->assertStringContainsString("'lead'", (string)$expression);
        $this->assertStringContainsString("'lead_daily'", (string)$expression);
        $this->assertStringNotContainsString("'results'", (string)$expression);
    }

    public function testUsesDriverDictionaryWhenOverrideMissing(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: static fn (string $channel): array => $channel === 'facebook_marketing'
                ? ['conversions' => ['driver_result', 'driver_result_daily']]
                : [],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'results',
            channel: 'facebook_marketing',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('conversions', $resolved['canonical_metric']);
        $this->assertSame('legacy_alias', $resolved['input_type']);
        $this->assertSame('conversions', $resolved['legacy_alias_of']);
        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['driver_result', 'driver_result_daily'], $resolved['raw_names']);
        $this->assertStringContainsString("'driver_result'", (string)$resolved['sql_expression']);
    }

    public function testUsesDriverRegistryProviderWhenAvailable(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: null,
            driverRegistryResolver: static fn (): array => [
                'facebook_marketing' => [
                    'driver' => FakeCanonicalMetricProviderDriver::class,
                ],
            ],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'results',
            channel: 'facebook_marketing',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['registry_result', 'registry_result_daily'], $resolved['raw_names']);
        $this->assertStringContainsString("'registry_result'", (string)$resolved['sql_expression']);
    }

    public function testResolvesSecondChannelViaDriverRegistryDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: null,
            driverRegistryResolver: static fn (): array => [
                'shopify' => [
                    'driver' => FakeShopifyCanonicalMetricProviderDriver::class,
                ],
            ],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'conversions',
            channel: 'shopify',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['shopify_orders', 'shopify_orders_daily'], $resolved['raw_names']);
        $this->assertStringContainsString("'shopify_orders'", (string)$resolved['sql_expression']);
    }

    public function testResolvesKlaviyoChannelViaDriverRegistryDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: null,
            driverRegistryResolver: static fn (): array => [
                'klaviyo' => [
                    'driver' => FakeKlaviyoCanonicalMetricProviderDriver::class,
                ],
            ],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'conversions',
            channel: 'klaviyo',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['klaviyo_conversion', 'klaviyo_conversion_daily'], $resolved['raw_names']);
        $this->assertStringContainsString("'klaviyo_conversion'", (string)$resolved['sql_expression']);
    }

    public function testResolvesGoogleChannelViaDriverRegistryDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: null,
            driverRegistryResolver: static fn (): array => [
                'google_search_console' => [
                    'driver' => FakeGoogleCanonicalMetricProviderDriver::class,
                ],
            ],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'clicks',
            channel: 'google_search_console',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['gsc_clicks', 'gsc_clicks_daily'], $resolved['raw_names']);
        $this->assertStringContainsString("'gsc_clicks'", (string)$resolved['sql_expression']);
    }
}

final class FakeCanonicalMetricProviderDriver implements CanonicalMetricDictionaryProviderInterface
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'conversions' => ['registry_result', 'registry_result_daily'],
        ];
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'id';
    }
}

final class FakeShopifyCanonicalMetricProviderDriver implements CanonicalMetricDictionaryProviderInterface
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'conversions' => ['shopify_orders', 'shopify_orders_daily'],
        ];
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'id';
    }
}

final class FakeKlaviyoCanonicalMetricProviderDriver implements CanonicalMetricDictionaryProviderInterface
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'conversions' => ['klaviyo_conversion', 'klaviyo_conversion_daily'],
        ];
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'id';
    }
}

final class FakeGoogleCanonicalMetricProviderDriver implements CanonicalMetricDictionaryProviderInterface
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'clicks' => ['gsc_clicks', 'gsc_clicks_daily'],
        ];
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'id';
    }
}

