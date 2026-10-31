<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\CompanionMetricWeightedFormula;

final class CompanionMetricWeightedFormulaTest extends TestCase
{
    /** @dataProvider executionForms */
    public function testUnequalWeightsMissingCompanionsAndGrainIsolation(bool $joined): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $columns = ['channel', 'account_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'channeled_ad_id', 'creative_id', 'event_id', 'channeled_event_id', 'page_id', 'post_id', 'product_id', 'customer_id', 'order_id', 'query_id', 'country_id', 'device_id', 'dimension_set_id', 'location_id', 'state_id', 'city_id'];
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, '.implode(', ', array_map(fn ($c) => "$c INTEGER", $columns)).')');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        foreach ([[1, 'bounceRate'], [2, 'sessions']] as [$id, $name]) $db->insert('metric_configs', ['id' => $id, 'name' => $name, 'period' => 'daily', 'channel' => 1, 'channeled_account_id' => 7, 'channeled_campaign_id' => 9]);
        foreach ([[1, '2026-10-01', .1], [2, '2026-10-01', 10], [1, '2026-10-02', .9], [2, '2026-10-02', 90]] as [$id, $date, $value]) $db->insert('metrics', ['metric_config_id' => $id, 'metric_date' => $date, 'value' => $value]);
        // Another campaign must not serve as a weight for this campaign.
        $db->insert('metric_configs', ['id' => 3, 'name' => 'sessions', 'period' => 'daily', 'channel' => 1, 'channeled_account_id' => 7, 'channeled_campaign_id' => 99]);
        $db->insert('metrics', ['metric_config_id' => 3, 'metric_date' => '2026-10-01', 'value' => 10000]);
        $builder = new CompanionMetricWeightedFormula();
        $rule = ['source_names' => ['bounceRate'], 'weight_names' => ['sessions']];
        $formula = $joined ? $builder->buildFromJoin($rule, 'cw') : $builder->build($rule, true);
        $join = $joined ? $builder->join($rule, 'cw', true) : '';
        $sql = 'SELECT '.$formula.' AS value FROM metrics m JOIN metric_configs mc ON mc.id=m.metric_config_id '.$join.' WHERE mc.channeled_campaign_id=9';
        if ($joined) {
            $queryPlan = json_encode($db->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql));
            self::assertStringNotContainsString('CORRELATED', $queryPlan);
        }
        self::assertEqualsWithDelta(.82, (float)$db->fetchOne($sql), .000001);
        $db->insert('metrics', ['metric_config_id' => 2, 'metric_date' => '2026-10-01', 'value' => 10]);
        self::assertNull($db->fetchOne($sql), 'Duplicate companions must remain unavailable.');
        $db->executeStatement("DELETE FROM metrics WHERE metric_config_id=2 AND metric_date='2026-10-01'");
        $db->insert('metrics', ['metric_config_id' => 2, 'metric_date' => '2026-10-01', 'value' => -1]);
        self::assertNull($db->fetchOne($sql), 'Negative companions must remain unavailable.');
        $db->executeStatement("UPDATE metrics SET value=0 WHERE metric_config_id=2 AND metric_date='2026-10-01'");
        self::assertEqualsWithDelta(.9, (float)$db->fetchOne($sql), .000001);
        $db->executeStatement('UPDATE metrics SET value=0 WHERE metric_config_id=2');
        self::assertNull($db->fetchOne($sql), 'Zero total sessions is undefined even when every companion exists.');
        $db->executeStatement("UPDATE metrics SET value=90 WHERE metric_config_id=2 AND metric_date='2026-10-02'");
        $db->insert('metrics', ['metric_config_id' => 1, 'metric_date' => '2026-10-03', 'value' => .5]);
        self::assertNull($db->fetchOne($sql), 'Incomplete weights must not silently produce a partial average.');
        $db->close();
    }

    public static function executionForms(): array { return [[false], [true]]; }
}
