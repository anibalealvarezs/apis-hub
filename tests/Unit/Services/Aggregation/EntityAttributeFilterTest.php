<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\EntityAttributeFilter;
use Services\Aggregation\EntityAttributeResolver;
use Services\Aggregation\FilterConditionResolver;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\CanonicalMetricSqlResolver;
use Services\Aggregation\LegacyAggregateExecutionContext;
use Services\Aggregation\Stages\LegacyAggregateFilterStage;
use Services\Aggregation\Strategies\UniversalSqlStrategy;
use Services\Aggregation\EntityAttributeCatalog;
use Services\Aggregation\AggregationPlanner;
use Helpers\Helpers;

final class EntityAttributeFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Helpers::setEntityManager(null);
        parent::tearDown();
    }

    public function testUniversalAndLegacyQueriesApplyTheSameEntityFilters(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../../../src/Entities'], true));
        Helpers::setEntityManager($em);
        $connection->executeStatement('CREATE TABLE channeled_campaigns (id INTEGER PRIMARY KEY, budget REAL, objective TEXT, buying_type TEXT)');
        $connection->executeStatement("INSERT INTO channeled_campaigns VALUES (1, 9, 'OUTCOME_SALES', 'AUCTION'), (2, 50, 'OUTCOME_SALES', 'AUCTION'), (3, 100, 'OUTCOME_LEADS', 'RESERVED')");
        $connection->executeStatement('CREATE TABLE metric_configs (id INTEGER PRIMARY KEY, name TEXT, period TEXT, channeled_campaign_id INTEGER)');
        $connection->executeStatement("INSERT INTO metric_configs VALUES (1, 'spend', 'daily', 1), (2, 'spend', 'daily', 2), (3, 'spend', 'daily', 3)");
        $connection->executeStatement('CREATE TABLE metrics (metric_config_id INTEGER, value REAL)');
        $connection->executeStatement('INSERT INTO metrics VALUES (1, 10), (2, 20), (3, 30)');
        $filters = (object)[
            'attributes.channeledCampaign.budget' => ['operator' => 'gte', 'value' => 10],
            'attributes.channeledCampaign.budget__1' => ['operator' => 'lte', 'value' => 100],
            'attributes.channeledCampaign.objective' => ['operator' => 'in', 'value' => ['OUTCOME_SALES', 'OUTCOME_LEADS']],
            'attributes.channeledCampaign.buyingType' => ['operator' => 'eq', 'value' => 'AUCTION'],
        ];
        $plan = new AggregationPlan(aggregations: ['spend' => 'spend'], filters: $filters);
        $strategy = new UniversalSqlStrategy(new CanonicalMetricSqlResolver(
            projectConfigResolver: static fn () => [], driverDictionaryResolver: static fn () => [], driverRegistryResolver: static fn () => []
        ));
        $rows = $strategy->execute($connection, $plan, false);
        self::assertEquals(20, $rows[0]['spend']);

        $qb = $connection->createQueryBuilder()->select('SUM(e.value) AS spend')->from('metrics', 'e')
            ->join('e', 'metric_configs', 'mc', 'e.metric_config_id = mc.id');
        $context = new LegacyAggregateExecutionContext($qb, $plan, [], $filters, null, null, [], null, null,
            'Entities\\Analytics\\Metric', true, false, [
                'safeLeftJoin' => static fn ($source, $table, $alias, $condition) => $qb->leftJoin($source, $table, $alias, $condition),
                'joinRelation' => static fn () => null,
            ]);
        (new LegacyAggregateFilterStage())->apply($context, [], false, static fn () => '',
            (new FilterConditionResolver())->resolve(...), static fn () => false);
        self::assertEquals(20, $qb->executeQuery()->fetchOne());
        self::assertSame(1, substr_count($qb->getSQL(), 'LEFT JOIN channeled_campaigns'));
    }

    public function testMappedAttributesAndNestedRelationshipsNeedNoChannelDefinitions(): void
    {
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../../../src/Entities'], true));
        $resolver = new EntityAttributeResolver($em);
        $budget = $resolver->resolve('attributes.channeledCampaign.budget');
        self::assertSame('float', $budget['type']);
        self::assertStringEndsWith('.budget', $budget['expression']);
        self::assertSame('channeled_campaigns', $budget['joins'][0]['table']);
        $nested = $resolver->resolve('attributes.channeledAd.channeledCampaign.buyingType');
        self::assertCount(2, $nested['joins']);
        self::assertStringEndsWith('.buying_type', $nested['expression']);
        self::assertArrayHasKey('attributes.channeledCampaign.objective', $resolver->describe());
        self::assertArrayHasKey('attributes.page.hostname', $resolver->describe());
        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('attributes.channeledCampaign.budget);DROP TABLE jobs');
    }

    public function testAttributeRequestsCannotUseStrategiesThatIgnoreThem(): void
    {
        $method = new \ReflectionMethod(AggregationPlanner::class, 'buildCandidateOptimizedStrategies');
        $args = [false, true, 'daily', ['attributes.channeledCampaign.budget' => ['operator' => 'gte', 'value' => 10]],
            ['spend' => 'spend'], [], 'daily', false, '2026-01-01', '2026-01-31'];
        self::assertSame(['universal_sql'], $method->invokeArgs(new AggregationPlanner(), $args));
        $args[7] = true;
        self::assertSame([], $method->invokeArgs(new AggregationPlanner(), $args));
    }

    public function testFacetValuesRespectChannelAndAccountScope(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../../../src/Entities'], true));
        $metadata = $em->getClassMetadata(\Entities\Analytics\Channeled\ChanneledCampaign::class);
        $columns = array_map(static fn ($column) => '"'.$column.'" TEXT', $metadata->getColumnNames());
        $connection->executeStatement('CREATE TABLE channeled_campaigns ('.implode(', ', $columns).')');
        $connection->executeStatement("INSERT INTO channeled_campaigns (id, objective) VALUES ('1', 'OUTCOME_SALES'), ('2', 'OUTCOME_LEADS'), ('3', 'OUTCOME_ENGAGEMENT')");
        $connection->executeStatement('CREATE TABLE metric_configs (channeled_campaign_id INTEGER, channel INTEGER, channeled_account_id INTEGER)');
        $connection->executeStatement('INSERT INTO metric_configs VALUES (1, 1, 42), (2, 1, 99), (3, 2, 42)');
        $catalog = (new EntityAttributeCatalog($em))->get(1, ['relation' => 'channeledCampaign', 'account' => ['42'], 'values' => true]);
        self::assertSame(['OUTCOME_SALES' => 'OUTCOME_SALES'], $catalog['attributes.channeledCampaign.objective']['options']);
    }

    public function testBudgetBoundariesAndMultipleSelectionsExecuteAgainstValues(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE campaigns (budget REAL, objective TEXT)');
        $db->exec("INSERT INTO campaigns VALUES (9, 'SALES'), (50, 'SALES'), (100, 'LEADS'), (1000, 'SALES')");
        $compiler = new EntityAttributeFilter();
        $resolver = new FilterConditionResolver();
        $budget = $compiler->compile('budget', $resolver->resolve(['operator' => 'between', 'value' => [10, 100]]), 'budget', 'float');
        $objective = $compiler->compile('objective', $resolver->resolve(['operator' => 'in', 'value' => ['SALES', 'LEADS']]), 'objective', 'string');
        $query = $db->prepare('SELECT budget FROM campaigns WHERE '.$budget['sql'].' AND '.$objective['sql'].' ORDER BY budget');
        $query->execute($budget['params'] + $objective['params']);
        self::assertSame([50.0, 100.0], $query->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('budget >= :budget', $compiler->compile('budget', $resolver->resolve(['operator' => '>=', 'value' => 50]), 'budget', 'float')['sql']);
        $this->expectException(InvalidArgumentException::class);
        $compiler->compile('budget', ['operator' => 'between', 'value' => [100, 10]], 'budget', 'float');
    }

    public function testBooleanValuesAndUnknownOperatorsAreValidated(): void
    {
        $compiler = new EntityAttributeFilter();
        self::assertSame(['enabled' => '0'], $compiler->compile('enabled', ['operator' => 'eq', 'value' => false], 'enabled', 'boolean')['params']);
        $this->expectException(InvalidArgumentException::class);
        (new FilterConditionResolver())->resolve(['operator' => 'made_up', 'value' => 100], true);
    }
}
