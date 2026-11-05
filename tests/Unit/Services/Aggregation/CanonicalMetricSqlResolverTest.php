<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
use Services\Aggregation\CanonicalMetricSqlResolver;
use Tests\Unit\BaseUnitTestCase;

final class CanonicalMetricSqlResolverTest extends BaseUnitTestCase
{
    public function testFollowerSnapshotUsesTheLatestObservationWithinTheWindow(): void
    {
        $resolver = new CanonicalMetricSqlResolver();
        $db = new \PDO('sqlite::memory:');
        $db->exec("CREATE TABLE metric_configs (id INT, name TEXT, period TEXT, post_id INT, dimension_set_id INT);
            CREATE TABLE metrics (metric_config_id INT, metric_date TEXT, value REAL);
            INSERT INTO metric_configs VALUES (1, 'follower_total', 'lifetime', NULL, NULL);
            INSERT INTO metrics VALUES (1, '2026-10-25', 12), (1, '2026-10-26', 9), (1, '2026-10-28', 20)");
        $formula = $resolver->resolveOrganicMetric('follower_total', 'facebook_organic', 'mc.name', 'mc.period')['sql_expression'];
        $query = $db->prepare("SELECT $formula FROM metrics m JOIN metric_configs mc ON mc.id = m.metric_config_id
            WHERE m.metric_date BETWEEN :startDate AND :endDate");

        $query->execute(['startDate' => '2026-10-25', 'endDate' => '2026-10-26']);
        self::assertEquals(9, $query->fetchColumn());

        $query->execute(['startDate' => '2026-10-27', 'endDate' => '2026-10-27']);
        self::assertNull($query->fetchColumn());
    }

    public function testGoogleAdsRoasUsesTotalsAndRejectsIncompleteOrLegacyWindows(): void
    {
        $resolver = new CanonicalMetricSqlResolver(driverDictionaryResolver: fn () => [
            'spend' => ['spend'],
            'roas_purchase' => ['roas_purchase'],
        ]);
        $formula = $resolver->resolveMarketingMetricExpression('roas_purchase', 'google_ads', 'm.name', 'm.period');
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE observations (name TEXT, period TEXT, value REAL)');
        $evaluate = fn () => $db->query("SELECT $formula FROM observations m")->fetchColumn();

        $db->exec("INSERT INTO observations VALUES ('spend','daily',2), ('conversion_value','daily',10), ('spend','daily',8), ('conversion_value','daily',0)");
        $this->assertEquals(1.0, $evaluate());

        $db->exec("INSERT INTO observations VALUES ('roas_purchase','daily',10)");
        $this->assertNull($evaluate());

        $db->exec("DELETE FROM observations WHERE name = 'roas_purchase'; DELETE FROM observations WHERE name = 'conversion_value' AND value = 0");
        $this->assertNull($evaluate());

        $db->exec("DELETE FROM observations; INSERT INTO observations VALUES ('spend','daily',0), ('conversion_value','daily',10)");
        $this->assertNull($evaluate());
    }

    public function testExplicitUnsupportedDictionaryEntryCannotFallBackToGenericCost(): void
    {
        $resolver = new CanonicalMetricSqlResolver(driverDictionaryResolver: fn () => ['spend' => [], 'revenue' => ['totalRevenue']]);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveMarketingMetric('spend', 'fixture', 'LOWER(mc.name)', 'LOWER(mc.period)');
    }

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

    public function testResolvesMailchimpMetricsViaDriverRegistryDictionary(): void
    {
        $resolver = new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn (): array => [],
            driverDictionaryResolver: null,
            driverRegistryResolver: static fn (): array => [
                'mailchimp' => [
                    'driver' => FakeMailchimpCanonicalMetricProviderDriver::class,
                ],
            ],
        );

        $resolved = $resolver->resolveMarketingMetric(
            requestedMetric: 'sends',
            channel: 'mailchimp',
            nameCol: 'LOWER(mc.name)',
            periodCol: 'LOWER(mc.period)',
        );

        $this->assertSame('driver', $resolved['source']);
        $this->assertSame(['sends', 'emails_sent'], $resolved['raw_names']);
        $this->assertNotNull($resolved['sql_expression']);
        $this->assertStringContainsString("'sends'", (string)$resolved['sql_expression']);
        $this->assertStringContainsString("'emails_sent'", (string)$resolved['sql_expression']);
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

final class FakeMailchimpCanonicalMetricProviderDriver implements CanonicalMetricDictionaryProviderInterface
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'sends' => ['sends', 'emails_sent'],
            'opens' => ['opens_total', 'opens_standard'],
            'clicks' => ['clicks_total', 'clicks_unique'],
            'bounces' => ['bounces_total', 'bounces_hard', 'bounces_soft'],
            'unsubscribes' => ['unsubscribes'],
            'orders' => ['orders_count'],
            'revenue' => ['revenue', 'total_revenue'],
        ];
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'id';
    }
}

