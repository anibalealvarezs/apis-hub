<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Repositories\BaseRepository;
use Services\Aggregation\AggregationExecutionResult;
use Services\Aggregation\AggregationExecutor;
use Services\Aggregation\AggregationPlan;
use Tests\Unit\BaseUnitTestCase;

final class AggregationExecutorTest extends BaseUnitTestCase
{
    public function testReturnsOptimizedExecutionWhenAvailable(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())
            ->method('executeOptimizedAggregationPlan')
            ->willReturn(new AggregationExecutionResult([
                ['clicks' => 10],
            ], [
                'execution_path' => 'optimized',
            ]));
        $repository->expects($this->never())
            ->method('executeLegacyAggregationPlan');

        $plan = new AggregationPlan(
            aggregations: ['clicks' => 'clicks'],
            preferredExecutionPath: 'optimized',
            canUseOptimized: true,
            candidateOptimizedStrategies: ['weighted_metric'],
        );

        $executor = new AggregationExecutor();
        $result = $executor->execute($repository, $plan);

        $this->assertSame([['clicks' => 10]], $result->getRows());
        $this->assertSame('optimized', $result->getMeta()['execution_path']);
    }

    public function testFallsBackToLegacyWithExplicitReason(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())
            ->method('executeOptimizedAggregationPlan')
            ->willReturn(null);
        $repository->expects($this->once())
            ->method('executeLegacyAggregationPlan')
            ->with(
                $this->isInstanceOf(AggregationPlan::class),
                'no_optimized_strategy_matched'
            )
            ->willReturn(new AggregationExecutionResult([
                ['clicks' => 5],
            ], [
                'execution_path' => 'legacy',
                'fallback_reason' => 'no_optimized_strategy_matched',
            ]));

        $plan = new AggregationPlan(
            aggregations: ['clicks' => 'clicks'],
            preferredExecutionPath: 'optimized',
            canUseOptimized: true,
            candidateOptimizedStrategies: ['weighted_metric'],
        );

        $executor = new AggregationExecutor();
        $result = $executor->execute($repository, $plan);

        $this->assertSame([['clicks' => 5]], $result->getRows());
        $this->assertSame('legacy', $result->getMeta()['execution_path']);
        $this->assertSame('no_optimized_strategy_matched', $result->getMeta()['fallback_reason']);
    }

    public function testPreservesPlannerProvidedFallbackReason(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())
            ->method('executeOptimizedAggregationPlan')
            ->willReturn(null);
        $repository->expects($this->once())
            ->method('executeLegacyAggregationPlan')
            ->with(
                $this->isInstanceOf(AggregationPlan::class),
                'unsupported_group_pattern'
            )
            ->willReturn(new AggregationExecutionResult([
                ['clicks' => 3],
            ], [
                'execution_path' => 'legacy',
                'fallback_reason' => 'unsupported_group_pattern',
            ]));

        $plan = new AggregationPlan(
            aggregations: ['clicks' => 'clicks'],
            preferredExecutionPath: 'optimized',
            canUseOptimized: true,
            fallbackReason: 'unsupported_group_pattern',
            candidateOptimizedStrategies: ['weighted_metric'],
        );

        $executor = new AggregationExecutor();
        $result = $executor->execute($repository, $plan);

        $this->assertSame([['clicks' => 3]], $result->getRows());
        $this->assertSame('unsupported_group_pattern', $result->getMeta()['fallback_reason']);
    }
}

