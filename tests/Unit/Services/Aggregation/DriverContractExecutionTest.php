<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Repositories\BaseRepository;
use Services\Aggregation\AggregationExecutor;
use Services\Aggregation\AggregationPlanner;
use Tests\Unit\BaseUnitTestCase;

final class DriverContractExecutionTest extends BaseUnitTestCase
{
    private function planner(): AggregationPlanner
    {
        return new AggregationPlanner(
            aggregationProfilesResolver: fn () => [['key' => 'fixture', 'channel' => 'fixture', 'query_contract' => [
                'version' => 1, 'scope_filter' => 'dimensions.scope', 'default_filters' => ['dimensions.scope' => 'users'],
                'scopes' => ['users' => ['reportedUsers']], 'blocked_metrics' => ['cost'],
                'rules' => ['reportedUsers' => ['reducer' => 'sum', 'quality' => 'not_unique']],
            ]]],
            channelKeyResolver: fn () => 'fixture',
        );
    }

    public function testValidatedContractReachesDedicatedExecutionAndKeepsQualityMetadata(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');
        $repository->method('getLastAggregateMeta')->willReturn([]);
        $repository->expects(self::never())->method('executeLegacyAggregationPlan');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);
        $repository->method('getEntityManager')->willReturn($this->entityManager);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(function ($sql, $parameters) {
            self::assertContains('users', $parameters);
            return [['users' => 20]];
        });
        $plan = $this->planner()->plan($repository, ['users' => 'reportedUsers'], filters: (object)['channel' => 'fixture']);
        $result = (new AggregationExecutor())->execute($repository, $plan);
        self::assertSame([['users' => 20]], $result->getRows());
        self::assertSame(['users' => 'not_unique'], $result->getMeta()['metric_quality']);
    }

    public function testRejectedCostNeverReachesAnyRepositoryExecution(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');
        $repository->expects(self::never())->method('getEntityManager');
        $repository->expects(self::never())->method('executeLegacyAggregationPlan');
        $this->expectException(\InvalidArgumentException::class);
        $this->planner()->plan($repository, ['cost' => 'cost'], filters: (object)['channel' => 'fixture']);
    }
}
