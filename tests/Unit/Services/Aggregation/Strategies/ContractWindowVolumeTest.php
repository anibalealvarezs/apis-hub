<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\CompanionMetricWeightedFormula;
use Services\Aggregation\Strategies\UniversalSqlStrategy;
use Tests\Unit\BaseUnitTestCase;

final class ContractWindowVolumeTest extends BaseUnitTestCase
{
    public function testFortyFiveThousandRowsUseOneFactScanForBothRates(): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, '.implode(', ', array_map(fn ($c)=>"$c INTEGER", CompanionMetricWeightedFormula::GRAIN_COLUMNS)).')');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        $db->executeStatement("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<1000), kinds(k,name) AS (VALUES (0,'averageSessionDuration'),(1,'bounceRate'),(2,'sessions')) INSERT INTO metric_configs (id,name,period,channel,channeled_account_id,dimension_set_id) SELECT x*3+k,name,'daily',1,46,x FROM n CROSS JOIN kinds");
        $db->executeStatement("WITH RECURSIVE days(d) AS (SELECT 1 UNION ALL SELECT d+1 FROM days WHERE d<15) INSERT INTO metrics SELECT mc.id, '2026-10-' || printf('%02d',d), CASE mc.name WHEN 'sessions' THEN 2 WHEN 'bounceRate' THEN .25 ELSE 100 END FROM metric_configs mc CROSS JOIN days");
        self::assertSame(45000, (int)$db->fetchOne('SELECT COUNT(*) FROM metrics'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(function ($sql,$params) use ($db) {
            self::assertSame(1, substr_count($sql, 'FROM metrics '));
            self::assertStringNotContainsString('LEFT JOIN LATERAL', $sql);
            $explain = json_encode($db->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql, $params));
            self::assertStringContainsString('MATERIALIZE contract_observations', $explain);
            self::assertStringNotContainsString('CORRELATED', $explain);
            return $db->fetchAllAssociative($sql,$params);
        });
        $rules = [
            'duration'=>['reducer'=>'companion_weighted_average','source_names'=>['averageSessionDuration'],'weight_names'=>['sessions']],
            'bounce'=>['reducer'=>'companion_weighted_average','source_names'=>['bounceRate'],'weight_names'=>['sessions']],
            'sessions'=>['reducer'=>'sum','source_names'=>['sessions']],
        ];
        $plan = new AggregationPlan(aggregations:['duration'=>'averageSessionDuration','bounce'=>'bounceRate','sessions'=>'sessions'], groupBy:['daily'], filters:(object)['channeledAccount'=>46], startDate:'2026-10-01', endDate:'2026-10-15', context:['query_contract'=>['resolved_rules'=>$rules]]);
        $rows = (new UniversalSqlStrategy)->execute($connection,$plan,true);
        self::assertCount(15,$rows);
        foreach ($rows as $row) {
            self::assertEqualsWithDelta(100,(float)$row['duration'],.000001);
            self::assertEqualsWithDelta(.25,(float)$row['bounce'],.000001);
            self::assertSame(2000.0,(float)$row['sessions']);
        }
        $db->close();
    }
}
