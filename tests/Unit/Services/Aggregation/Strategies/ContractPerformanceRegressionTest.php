<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\CompanionMetricWeightedFormula;
use Services\Aggregation\Strategies\UniversalSqlStrategy;
use Tests\Unit\BaseUnitTestCase;

final class ContractPerformanceRegressionTest extends BaseUnitTestCase
{
    public function testChannelAndScopePruneBothScansWithoutChangingWeightedResults(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $grain = CompanionMetricWeightedFormula::GRAIN_COLUMNS;
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, '.implode(', ', array_map(fn ($c) => "$c INTEGER", $grain)).')');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        $db->executeStatement('CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
        $db->executeStatement("INSERT INTO channels VALUES (1, 'google_analytics'), (2, 'other')");
        $db->executeStatement('CREATE TABLE dimension_keys (id INTEGER PRIMARY KEY, name TEXT)');
        $db->executeStatement('CREATE TABLE dimension_values (id INTEGER PRIMARY KEY, dimension_key_id INTEGER, value TEXT)');
        $db->executeStatement('CREATE TABLE dimension_set_items (dimension_set_id INTEGER, dimension_value_id INTEGER)');
        $db->executeStatement("INSERT INTO dimension_keys VALUES (1, 'scope')");
        $db->executeStatement("INSERT INTO dimension_values VALUES (1,1,'traffic_matrix'), (2,1,'event_matrix')");
        $db->executeStatement('INSERT INTO dimension_set_items VALUES (1,1), (2,2)');
        foreach ([[1,1,1,'averageSessionDuration'],[2,1,1,'sessions'],[3,1,2,'averageSessionDuration'],[4,1,2,'sessions'],[5,2,1,'averageSessionDuration'],[6,2,1,'sessions']] as [$id,$channel,$set,$name]) {
            $db->insert('metric_configs', ['id'=>$id,'channel'=>$channel,'dimension_set_id'=>$set,'channeled_account_id'=>46,'name'=>$name,'period'=>'daily']);
        }
        foreach ([[1,'2026-01-01',10],[2,'2026-01-01',1],[1,'2026-10-29',100],[2,'2026-10-29',9],[3,'2026-01-01',999],[4,'2026-01-01',999],[5,'2026-01-01',888],[6,'2026-01-01',888]] as [$config,$date,$value]) {
            $db->insert('metrics', ['metric_config_id'=>$config,'metric_date'=>$date,'value'=>$value]);
        }
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(function ($sql, $params) use ($db) {
            self::assertStringContainsString('mc.channel IN (SELECT id FROM channels', $sql);
            self::assertStringContainsString('wc.channel IN (SELECT id FROM channels', $sql);
            self::assertStringContainsString('wc.dimension_set_id IN (SELECT wi.dimension_set_id', $sql);
            self::assertStringStartsWith('WITH contract_observations AS MATERIALIZED', $sql);
            self::assertStringNotContainsString('LEFT JOIN (SELECT wc.channel', $sql);
            self::assertStringContainsString('OVER contract_grain', $sql);
            self::assertSame('traffic_matrix', $params['companion_scope_value']);
            self::assertSame('google_analytics', $params['filter_channel_0']);
            return $db->fetchAllAssociative($sql, $params);
        });
        $contract = ['scope_filter'=>'dimensions.scope','resolved_rules'=>[
            'duration'=>['reducer'=>'companion_weighted_average','source_names'=>['averageSessionDuration'],'weight_names'=>['sessions']],
            'sessions'=>['reducer'=>'sum','source_names'=>['sessions']],
        ]];
        $plan = new AggregationPlan(aggregations:['duration'=>'averageSessionDuration','sessions'=>'sessions'], filters:(object)['channel'=>'google_analytics','channeledAccount'=>'46','dimensions.scope'=>'traffic_matrix'], startDate:'2025-01-01', endDate:'2026-10-29', context:['query_contract'=>$contract]);
        $strategy = new UniversalSqlStrategy;
        $rows = $strategy->execute($connection, $plan, true);
        self::assertEqualsWithDelta(91, (float)$rows[0]['duration'], .000001);
        self::assertSame(10.0, (float)$rows[0]['sessions']);
        $db->insert('metrics',['metric_config_id'=>2,'metric_date'=>'2026-01-01','value'=>1]);
        self::assertNull($strategy->execute($connection, $plan, true)[0]['duration'], 'Duplicate companions must still fail closed.');
        $db->executeStatement('DELETE FROM metrics WHERE metric_config_id=2');
        self::assertNull($strategy->execute($connection, $plan, true)[0]['duration'], 'Missing companions must still fail closed.');
        $db->close();
    }
}
