<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationExecutionResult;
    use Services\Aggregation\AggregationExecutor;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\AggregationTelemetryEventRecorder;
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
            $this->assertSame('optimized', $result->getMeta()['executor_path_decision']);
            $this->assertTrue($result->getMeta()['optimized_attempted']);
            $this->assertSame(1, $result->getMeta()['optimized_candidate_count']);
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
                    'execution_path'  => 'legacy',
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
            $this->assertSame('legacy', $result->getMeta()['executor_path_decision']);
            $this->assertTrue($result->getMeta()['optimized_attempted']);
            $this->assertSame(1, $result->getMeta()['optimized_candidate_count']);
            $this->assertSame('no_optimized_strategy_matched', $result->getMeta()['executor_fallback_reason']);
            $this->assertSame('executor', $result->getMeta()['executor_fallback_reason_source']);
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
                    'execution_path'  => 'legacy',
                    'fallback_reason' => 'unsupported_group_pattern',
                ]));

            $plan = new AggregationPlan(
                aggregations: ['clicks' => 'clicks'],
                preferredExecutionPath: 'optimized',
                canUseOptimized: true,
                fallbackReason: 'unsupported_group_pattern',
                stages: [
                    'grouping' => [
                        'normalized_pattern' => null,
                    ],
                    'filters'  => [
                        'unsupported_operators' => ['gt'],
                    ],
                    'reducers' => [
                        'missing_reducer_expressions' => ['mystery_ratio'],
                    ],
                ],
                candidateOptimizedStrategies: ['weighted_metric'],
            );

            $executor = new AggregationExecutor();
            $result = $executor->execute($repository, $plan);

            $this->assertSame([['clicks' => 3]], $result->getRows());
            $this->assertSame('unsupported_group_pattern', $result->getMeta()['fallback_reason']);
            $this->assertSame('planner', $result->getMeta()['executor_fallback_reason_source']);
            $this->assertSame(
                [
                    'fallback_reason'              => 'unsupported_group_pattern',
                    'unsupported_filter_operators' => ['gt'],
                    'missing_reducer_expressions'  => ['mystery_ratio'],
                ],
                $result->getMeta()['planner_diagnostics']
            );
        }

        public function testIncludesProfileStageDiagnosticsWhenPresent(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('executeOptimizedAggregationPlan')
                ->willReturn(null);
            $repository->expects($this->once())
                ->method('executeLegacyAggregationPlan')
                ->willReturn(new AggregationExecutionResult([
                    ['clicks' => 9],
                ], [
                    'execution_path'  => 'legacy',
                    'fallback_reason' => 'missing_profile_capability',
                ]));

            $plan = new AggregationPlan(
                aggregations: ['clicks' => 'clicks'],
                preferredExecutionPath: 'optimized',
                canUseOptimized: true,
                fallbackReason: 'missing_profile_capability',
                stages: [
                    'profiles' => [
                        'checked'        => true,
                        'supported'      => false,
                        'channel'        => 'google_search_console',
                        'profile_count'  => 1,
                        'failure_reason' => 'no_matching_profile',
                    ],
                ],
                candidateOptimizedStrategies: ['weighted_metric'],
            );

            $executor = new AggregationExecutor();
            $result = $executor->execute($repository, $plan);

            $this->assertSame('legacy', $result->getMeta()['executor_path_decision']);
            $this->assertSame('planner', $result->getMeta()['executor_fallback_reason_source']);
            $this->assertSame(
                [
                    'fallback_reason'        => 'missing_profile_capability',
                    'profile_checked'        => true,
                    'profile_supported'      => false,
                    'profile_channel'        => 'google_search_console',
                    'profile_count'          => 1,
                    'profile_failure_reason' => 'no_matching_profile',
                ],
                $result->getMeta()['planner_diagnostics']
            );
        }

        public function testExecuteAggregateOwnsPlannerAndTelemetryWiring(): void
        {
            $telemetryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aggregation-executor-wiring-'.bin2hex(random_bytes(6)).'.jsonl';

            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Metric');
            $repository->expects($this->once())
                ->method('executeOptimizedAggregationPlan')
                ->willReturn(new AggregationExecutionResult([
                    ['position' => 12.5],
                ], [
                    'execution_path' => 'optimized',
                ]));
            $repository->expects($this->never())
                ->method('executeLegacyAggregationPlan');

            $executor = new AggregationExecutor(
                planner: null,
                telemetryRecorder: new AggregationTelemetryEventRecorder($telemetryPath),
            );

            try {
                $result = $executor->executeAggregate(
                    repository: $repository,
                    aggregations: ['position' => 'position'],
                    groupBy: ['daily'],
                    filters: (object)['channel' => 'google_search_console'],
                    startDate: '2026-04-01',
                    endDate: '2026-04-30',
                );

                $date = date('Y-m-d');
                $suffixedPath = substr($telemetryPath, 0, -6) . '-' . $date . '.jsonl';

                $this->assertSame('optimized', $result->getMeta()['execution_path']);
                $this->assertSame('optimized', $result->getMeta()['planned_execution_path']);
                $this->assertSame(['weighted_metric'], $result->getMeta()['candidate_optimized_strategies']);
                $this->assertFileExists($suffixedPath);

                $lines = file($suffixedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $this->assertIsArray($lines);
                $this->assertCount(1, $lines);
                $event = json_decode((string)$lines[0], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('Entities\\Analytics\\Metric', $event['entity_name']);
                $this->assertSame(['channel'], $event['filter_keys']);
                $this->assertSame(['daily'], $event['group_by']);
                $this->assertSame('optimized', $event['planned_execution_path']);
            } finally {
                $date = date('Y-m-d');
                $suffixedPath = substr($telemetryPath, 0, -6) . '-' . $date . '.jsonl';
                @unlink($suffixedPath);
            }
        }

        public function testUsesStrategyFallbackReasonWhenPlannerDoesNotProvideOne(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('executeOptimizedAggregationPlan')
                ->willReturn(null);
            $repository->expects($this->once())
                ->method('getLastAggregateMeta')
                ->willReturn([
                    'strategy_fallback_reason' => 'missing_metric_equivalence',
                ]);
            $repository->expects($this->once())
                ->method('executeLegacyAggregationPlan')
                ->with(
                    $this->isInstanceOf(AggregationPlan::class),
                    'missing_metric_equivalence'
                )
                ->willReturn(new AggregationExecutionResult([
                    ['clicks' => 1],
                ], [
                    'execution_path' => 'legacy',
                    'fallback_reason' => 'missing_metric_equivalence',
                ]));

            $plan = new AggregationPlan(
                aggregations: ['clicks' => 'clicks'],
                preferredExecutionPath: 'optimized',
                canUseOptimized: true,
                candidateOptimizedStrategies: ['marketing_hierarchy'],
            );

            $executor = new AggregationExecutor();
            $result = $executor->execute($repository, $plan);

            $this->assertSame('missing_metric_equivalence', $result->getMeta()['executor_fallback_reason']);
            $this->assertSame('strategy', $result->getMeta()['executor_fallback_reason_source']);
        }

        public function testTelemetryEventIncludesMetricResolutionMetadata(): void
        {
            $telemetryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aggregation-executor-metric-resolution-'.bin2hex(random_bytes(6)).'.jsonl';

            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\Analytics\Channeled\ChanneledMetric');
            $repository->expects($this->once())
                ->method('executeOptimizedAggregationPlan')
                ->willReturn(new AggregationExecutionResult([
                    ['conversions' => 4],
                ], [
                    'execution_path' => 'optimized',
                    'metric_resolution' => [
                        'channel' => 'facebook_marketing',
                        'strategy' => 'marketing_hierarchy',
                        'resolved_metrics' => [
                            [
                                'requested_metric' => 'cost_per_result',
                                'canonical_metric' => 'cost_per_conversion',
                                'raw_names' => ['results', 'results_daily'],
                                'source' => 'driver',
                            ],
                        ],
                    ],
                ]));

            $executor = new AggregationExecutor(
                planner: null,
                telemetryRecorder: new AggregationTelemetryEventRecorder($telemetryPath),
            );

            try {
                $result = $executor->executeAggregate(
                    repository: $repository,
                    aggregations: ['conversions' => 'cost_per_result'],
                    groupBy: ['daily'],
                    filters: (object)['channel' => 'facebook_marketing'],
                    startDate: '2026-04-01',
                    endDate: '2026-04-30',
                );

                $date = date('Y-m-d');
                $suffixedPath = substr($telemetryPath, 0, -6) . '-' . $date . '.jsonl';

                $this->assertSame('optimized', $result->getMeta()['execution_path']);
                $this->assertFileExists($suffixedPath);

                $lines = file($suffixedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $this->assertIsArray($lines);
                $this->assertCount(1, $lines);
                $event = json_decode((string)$lines[0], true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame('facebook_marketing', $event['metric_resolution']['channel']);
                $this->assertSame('marketing_hierarchy', $event['metric_resolution']['strategy']);
                $this->assertSame('cost_per_conversion', $event['metric_resolution']['resolved_metrics'][0]['canonical_metric']);
                $this->assertSame(['results', 'results_daily'], $event['metric_resolution']['resolved_metrics'][0]['raw_names']);
            } finally {
                $date = date('Y-m-d');
                $suffixedPath = substr($telemetryPath, 0, -6) . '-' . $date . '.jsonl';
                @unlink($suffixedPath);
            }
        }
    }

