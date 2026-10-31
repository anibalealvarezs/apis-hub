<?php
namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\BoundedTemporalBuckets;
use Services\Aggregation\CompanionMetricWeightedFormula;

final class BoundedTemporalBucketsTest extends TestCase
{
    public function testTrailingBucketsUseCompanionWeightsAcrossDaysAndNeverSumDailyAverages(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $columns = CompanionMetricWeightedFormula::GRAIN_COLUMNS;
        $db->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, '.implode(', ', array_map(fn ($c) => "$c INTEGER", $columns)).')');
        $db->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, metric_date TEXT, value REAL)');
        foreach ([[1, 'averageSessionDuration'], [2, 'sessions']] as [$id, $name]) $db->insert('metric_configs', ['id' => $id, 'name' => $name, 'period' => 'daily', 'channel' => 1, 'channeled_account_id' => 7]);
        foreach ([[1, '2026-10-01', 10], [2, '2026-10-01', 1], [1, '2026-10-02', 100], [2, '2026-10-02', 9], [1, '2026-10-09', 20], [2, '2026-10-09', 2]] as [$id, $date, $value]) $db->insert('metrics', ['metric_config_id' => $id, 'metric_date' => $date, 'value' => $value]);
        $builder = new CompanionMetricWeightedFormula;
        $rule = ['source_names' => ['averageSessionDuration'], 'weight_names' => ['sessions']];
        $bucket = (new BoundedTemporalBuckets)->expression('trailing_weekly', 'm.metric_date', '2026-10-01', '2026-10-09');
        $rows = $db->fetchAllAssociative('SELECT '.$bucket.' AS bucket, '.$builder->buildFromJoin($rule, 'cw').' AS duration FROM metrics m JOIN metric_configs mc ON mc.id=m.metric_config_id '.$builder->join($rule, 'cw', true).' GROUP BY '.$bucket.' ORDER BY bucket');
        self::assertSame(['2026-10-02', '2026-10-09'], array_column($rows, 'bucket'));
        self::assertEqualsWithDelta(91, (float)$rows[0]['duration'], .00001);
        self::assertEqualsWithDelta(20, (float)$rows[1]['duration'], .00001);
        $plan = new \Services\Aggregation\AggregationPlan(aggregations: ['duration' => 'averageSessionDuration'], groupBy: ['trailing_weekly'], startDate: '2026-10-01', endDate: '2026-10-09', context: ['query_contract' => ['resolved_rules' => ['duration' => $rule + ['reducer' => 'companion_weighted_average']]]]);
        $actual = (new \Services\Aggregation\Strategies\UniversalSqlStrategy)->execute($db, $plan, true);
        $byBucket = array_column($actual, 'duration', 'trailing_weekly');
        self::assertEqualsWithDelta(91, (float)$byBucket['2026-10-02'], .00001);
        self::assertEqualsWithDelta(20, (float)$byBucket['2026-10-09'], .00001);
        $db->close();
    }

    /** @dataProvider invalidWindows */
    public function testInvalidWindowsAreRejected(string $start, string $end): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BoundedTemporalBuckets)->expression('trailing_monthly', 'm.metric_date', $start, $end);
    }
    public static function invalidWindows(): array { return [['2026-02-30', '2026-03-30'], ['2026-10-10', '2026-10-01'], ["2026-01-01'", '2026-10-01'], ['2000-01-01', '2026-01-01']]; }
}
