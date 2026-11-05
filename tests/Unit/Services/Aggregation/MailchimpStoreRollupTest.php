<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\AgnosticPreAggregationEngine;
use Services\Aggregation\CanonicalMetricSqlResolver;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\Strategies\UniversalSqlStrategy;

final class MailchimpStoreRollupTest extends TestCase
{
    public function testStoreIdentityReplayAndCurrencyIsolation(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE channeled_accounts (id INTEGER PRIMARY KEY, platform_id TEXT, channel INTEGER, account_id INTEGER)',
            'CREATE TABLE dimension_sets (id INTEGER PRIMARY KEY, hash TEXT UNIQUE)',
            'CREATE TABLE dimension_keys (id INTEGER PRIMARY KEY, name TEXT UNIQUE)',
            'CREATE TABLE dimension_values (id INTEGER PRIMARY KEY, dimension_key_id INTEGER, value TEXT, UNIQUE(dimension_key_id, value))',
            'CREATE TABLE dimension_set_items (dimension_set_id INTEGER, dimension_value_id INTEGER, UNIQUE(dimension_set_id, dimension_value_id))',
            'CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, channel INTEGER, name TEXT, period TEXT, account_id INTEGER, channeled_account_id INTEGER, campaign_id INTEGER, channeled_campaign_id INTEGER, dimension_set_id INTEGER, config_signature TEXT UNIQUE)',
            'CREATE TABLE metrics (id INTEGER PRIMARY KEY, value REAL, metadata TEXT, dimensions_hash TEXT, metric_config_id INTEGER, metric_date TEXT, UNIQUE(metric_config_id, dimensions_hash, metric_date))',
            "INSERT INTO channels VALUES (4, 'mailchimp')",
            "INSERT INTO channeled_accounts VALUES (42, 'audience', 4, 7)",
        ] as $sql) {
            $connection->executeStatement($sql);
        }
        $engine = new class($connection) extends AgnosticPreAggregationEngine {
            public array $records = [];
            protected function resolveChannelId(string|int $channel): int
            {
                return 4;
            }
            protected function fetchAtomicRecordsForDate(string $tableName, string $channel, string $dateStr, bool $throwOnFailure = false): array
            {
                return $this->records;
            }
        };
        $record = static fn (string $store, string $currency, float $amount, string $id): array => [
            'platform_id' => $id, 'platform_created_at' => '2026-11-01 12:00:00',
            'data' => ['store_id' => $store, 'currency' => $currency, 'audience_id' => 'audience', 'total_amount' => $amount],
        ];
        $engine->records = [$record('a', 'USD', 12, '1'), $record('b', 'USD', 30, '2')];
        $legacyOrder = $record('a', 'USD', 1000, 'legacy');
        unset($legacyOrder['data']['audience_id']);
        $engine->records[] = $legacyOrder;
        $rules = ['store_conversions' => [
            'source_entity' => 'channeled_orders', 'scope_field' => 'store_id',
            'identity_dimensions' => ['store_id' => 'store_id', 'currency' => 'currency'],
            'account_platform_id_field' => 'audience_id', 'require_source_date' => true,
            'metrics' => ['orders_count' => ['field' => 'platform_id', 'reducer' => 'count_distinct'],
                'revenue' => ['field' => 'total_amount', 'reducer' => 'sum']],
        ]];
        $rollup = static fn () => $engine->preAggregateDay($rules, 'mailchimp', '2026-11-01', 30);
        $rollup();
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM metrics'));
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(DISTINCT config_signature) FROM metric_configs'));
        self::assertSame(42, (int) $connection->fetchOne('SELECT MIN(channeled_account_id) FROM metric_configs'));
        $rollup();
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM metrics'));

        $resolver = new CanonicalMetricSqlResolver();
        $revenue = $resolver->resolveMarketingMetricExpression('revenue', 'mailchimp', 'mc.name', 'mc.period');
        $evaluate = static fn () => $connection->fetchOne("SELECT $revenue FROM metrics m JOIN metric_configs mc ON mc.id=m.metric_config_id");
        self::assertEquals(42, $evaluate());
        $connection->executeStatement("CREATE TRIGGER fail_metric BEFORE INSERT ON metrics WHEN NEW.value = 999 BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $engine->records[1] = $record('b', 'USD', 999, '2');
        try {
            $rollup();
            self::fail('The failed write must abort the rebuild.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('fixture failure', $exception->getMessage());
        }
        self::assertEquals(42, $evaluate());
        $connection->executeStatement('DROP TRIGGER fail_metric');
        $engine->records[1] = $record('b', 'EUR', 8, '2');
        $rollup();
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM metrics'));
        self::assertNull($evaluate());
        $plan = new AggregationPlan(
            aggregations: ['orders' => 'orders', 'revenue' => 'revenue'],
            groupBy: ['dimensions.store_id', 'dimensions.currency'],
            filters: (object) ['channel' => 'mailchimp', 'channeledAccount' => '42'],
            startDate: '2026-11-01', endDate: '2026-11-01',
        );
        $rows = (new UniversalSqlStrategy())->execute($connection, $plan, false);
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing([8, 12], array_column($rows, 'revenue'));

        $engine->records = [$record('a', 'USD', 0, '1')];
        $rollup();
        self::assertEquals(0, $evaluate());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM metrics'));
        $connection->executeStatement("INSERT INTO metric_configs (id, channel, name, period, channeled_account_id, config_signature) VALUES (999, 4, 'revenue', 'daily', 42, 'legacy')");
        $connection->executeStatement("INSERT INTO metrics (value, metric_config_id, metric_date, dimensions_hash) VALUES (1000, 999, '2026-11-01', 'legacy')");
        self::assertEquals(0, $evaluate());
    }
}
