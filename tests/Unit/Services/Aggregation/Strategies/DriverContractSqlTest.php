<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Strategies;

use Doctrine\DBAL\DriverManager;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\Strategies\UniversalSqlStrategy;
use Tests\Unit\BaseUnitTestCase;

final class DriverContractSqlTest extends BaseUnitTestCase
{
    public function testVirtualDimensionGroupsAndFiltersExistingSplitCache(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, dimension_set_id INTEGER)');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        $db->executeStatement('CREATE TABLE dimension_keys (id INTEGER PRIMARY KEY, name TEXT)');
        $db->executeStatement('CREATE TABLE dimension_values (id INTEGER PRIMARY KEY, dimension_key_id INTEGER, value TEXT)');
        $db->executeStatement('CREATE TABLE dimension_set_items (dimension_set_id INTEGER, dimension_value_id INTEGER)');
        foreach ([1 => 'source', 2 => 'medium'] as $id => $name) $db->insert('dimension_keys', compact('id', 'name'));
        foreach ([[1, 1, 'google'], [2, 2, 'organic'], [3, 2, 'cpc']] as [$id, $key, $value]) $db->insert('dimension_values', ['id' => $id, 'dimension_key_id' => $key, 'value' => $value]);
        foreach ([[1, 1], [1, 2], [2, 1], [2, 3]] as [$set, $value]) $db->insert('dimension_set_items', ['dimension_set_id' => $set, 'dimension_value_id' => $value]);
        foreach ([[1, 1, 10], [2, 2, 20]] as [$id, $set, $value]) {
            $db->insert('metric_configs', ['id' => $id, 'name' => 'sessions', 'period' => 'daily', 'dimension_set_id' => $set]);
            $db->insert('metrics', ['metric_config_id' => $id, 'metric_date' => '2026-10-01', 'value' => $value]);
        }
        $contract = ['derived_dimensions' => ['sessionSourceMedium' => ['parts' => ['source', 'medium'], 'separator' => ' / ']], 'resolved_rules' => ['sessions' => ['reducer' => 'sum', 'source_names' => ['sessions']]]];
        foreach ([['operator' => 'eq', 'value' => 'google / organic'], ['operator' => 'not_in', 'value' => ['google / cpc']], ['operator' => 'neq', 'value' => 'google / cpc']] as $filter) {
            $plan = new AggregationPlan(aggregations: ['sessions' => 'sessions'], groupBy: ['dimensions.sessionSourceMedium'], filters: (object)['dimensions.sessionSourceMedium' => $filter], context: ['channel' => 'contract_fixture', 'query_contract' => $contract]);
            $rows = (new UniversalSqlStrategy())->execute($db, $plan, true);
            self::assertCount(1, $rows);
            self::assertSame('google / organic', $rows[0]['dimensions.sessionSourceMedium']);
            self::assertSame(10.0, (float)$rows[0]['sessions']);
        }
        $db->close();
    }
}
