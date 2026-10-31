<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\CompanionMetricWeightedFormula;

final class IndexedCompanionLookupTest extends TestCase
{
    public function testCandidateKeepsCompleteGrainAndCountsDuplicateObservations(): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $columns = CompanionMetricWeightedFormula::GRAIN_COLUMNS;
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, '.implode(', ', array_map(fn ($c)=>"$c INTEGER", $columns)).')');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        $builder = new CompanionMetricWeightedFormula;
        $rule = ['weight_names'=>['sessions']];
        $query = $builder->indexedLookup($rule, ['bounceRate']);
        self::assertStringNotContainsString('GROUP BY', $query);
        self::assertStringContainsString('wc.dimension_set_id = mc.dimension_set_id OR', $query);
        $query = preg_replace('/\bmc\.([a-z_]+)/', ':source_$1', $query);
        $query = preg_replace('/\bm\.metric_date\b/', ':source_date', $query);
        $params = array_combine(array_map(fn ($c)=>'source_'.$c, $columns), array_fill(0, count($columns), null));
        $params += ['source_name'=>'bounceRate','source_period'=>'daily','source_date'=>'2026-10-01'];
        $params['source_channel']=1; $params['source_channeled_account_id']=7;
        $read = fn ()=>$db->fetchAssociative($query, $params);
        self::assertSame(0, (int)$read()['observation_count']);
        foreach ([[1,1,null],[2,1,0],[3,2,null]] as [$id,$channel,$set]) {
            $db->insert('metric_configs',['id'=>$id,'channel'=>$channel,'channeled_account_id'=>7,'dimension_set_id'=>$set,'name'=>'sessions','period'=>'daily']);
            $db->insert('metrics',['metric_config_id'=>$id,'metric_date'=>'2026-10-01','value'=>$id===1?9:10000]);
        }
        self::assertSame(1, (int)$read()['observation_count']);
        self::assertSame(9.0, (float)$read()['weight_value']);
        $db->insert('metrics',['metric_config_id'=>1,'metric_date'=>'2026-10-02','value'=>999]);
        self::assertSame(1, (int)$read()['observation_count'], 'Another date must not become a companion.');
        $db->insert('metrics',['metric_config_id'=>1,'metric_date'=>'2026-10-01','value'=>2]);
        self::assertSame(2, (int)$read()['observation_count'], 'Duplicates must remain detectable by the existing guard.');
        $params['source_name']='sessions';
        self::assertSame(0, (int)$db->fetchAssociative($query, $params)['observation_count'], 'Additive rows must not need companion lookups.');
        self::assertStringStartsWith('LEFT JOIN LATERAL', $builder->indexedJoin($rule, 'weights', ['bounceRate']));
        $db->close();
    }
}
