<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\MetricCurrencyGuardFormula;

final class MetricCurrencyGuardFormulaTest extends TestCase
{
    public function testMultiAccountTotalsRequireMatchingKnownCurrencies(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->getNativeConnection()->sqliteCreateFunction('JSON_UNQUOTE', fn ($v) => $v);
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, channeled_account_id INTEGER)');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metadata TEXT)');
        foreach ([1, 2] as $id) {
            $db->insert('metric_configs', ['id' => $id, 'name' => 'totalRevenue', 'period' => 'daily', 'channeled_account_id' => $id]);
            $db->insert('metrics', ['metric_config_id' => $id, 'metadata' => '{"ga4_report_quality":{"currencyCode":"USD"}}']);
        }
        $formula = (new MetricCurrencyGuardFormula())->build(['source_names' => ['totalRevenue'], 'currency_metadata_path' => ['ga4_report_quality', 'currencyCode']], false);
        $sql = 'SELECT '.$formula.' AS invalid_currency FROM metrics m JOIN metric_configs mc ON mc.id=m.metric_config_id';
        self::assertSame(0, (int)$db->fetchOne($sql));
        $db->update('metrics', ['metadata' => '{"ga4_report_quality":{"currencyCode":"EUR"}}'], ['metric_config_id' => 2]);
        self::assertSame(1, (int)$db->fetchOne($sql));
        $db->update('metrics', ['metadata' => null], ['metric_config_id' => 2]);
        self::assertSame(1, (int)$db->fetchOne($sql));
        $db->delete('metrics', ['metric_config_id' => 2]);
        self::assertSame(0, (int)$db->fetchOne($sql), 'Single-account historical reporting remains available.');
        $db->close();
    }
}
