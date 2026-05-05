<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Doctrine\DBAL\Exception;
    use Exceptions\ConfigurationException;
    use Repositories\BaseRepository;

    final readonly class AggregationExecutor
    {
        public function __construct(
            private ?AggregationPlanner                $planner = null,
            private ?AggregationTelemetryEventRecorder $telemetryRecorder = null,
        )
        {
        }

        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @throws ConfigurationException
         * @throws Exception
         */
        public function executeAggregate(
            BaseRepository $repository,
            array          $aggregations,
            array          $groupBy = [],
            ?object        $filters = null,
            ?string        $startDate = null,
            ?string        $endDate = null,
            ?string        $orderBy = null,
            ?string        $orderDir = 'ASC'
        ): AggregationExecutionResult
        {
            $planner = $this->planner ?? new AggregationPlanner();
            $plan = $planner->plan(
                repository: $repository,
                aggregations: $aggregations,
                groupBy: $groupBy,
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
                orderBy: $orderBy,
                orderDir: $orderDir,
            );

            $plannerMeta = [
                'planned_execution_path' => $plan->getPreferredExecutionPath(),
            ];
            if ($plan->getCandidateOptimizedStrategies() !== []) {
                $plannerMeta['candidate_optimized_strategies'] = $plan->getCandidateOptimizedStrategies();
            }

            $result = $this->withExecutorMeta(
                $this->execute($repository, $plan),
                $plannerMeta,
            );

            $this->recordTelemetryEvent(
                repository: $repository,
                aggregations: $aggregations,
                groupBy: $groupBy,
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
                rows: $result->getRows(),
                meta: $result->getMeta(),
            );

            return $result;
        }

        /**
         * @throws ConfigurationException
         * @throws Exception
         */
        public function execute(BaseRepository $repository, AggregationPlan $plan): AggregationExecutionResult
        {
            $optimizedAttempted = $plan->canUseOptimized();
            $candidateStrategies = $plan->getCandidateOptimizedStrategies();
            $plannerDiagnostics = $this->buildPlannerDiagnostics($plan);

            if ($plan->canUseOptimized()) {
                $optimizedResult = $repository->executeOptimizedAggregationPlan($plan);
                if ($optimizedResult !== null) {
                    return $this->withExecutorMeta(
                        $optimizedResult,
                        [
                            'executor_path_decision'    => 'optimized',
                            'optimized_attempted'       => true,
                            'optimized_candidate_count' => count($candidateStrategies),
                        ] + ($plannerDiagnostics !== [] ? ['planner_diagnostics' => $plannerDiagnostics] : [])
                    );
                }
            }

            $plannerFallbackReason = $plan->getFallbackReason();
            $strategyFallbackReason = null;
            $lastMeta = $repository->getLastAggregateMeta();
            if (isset($lastMeta['strategy_fallback_reason']) && is_string($lastMeta['strategy_fallback_reason'])) {
                $normalized = strtolower(trim($lastMeta['strategy_fallback_reason']));
                if ($normalized !== '') {
                    $strategyFallbackReason = $normalized;
                }
            }

            $fallbackReason = $plannerFallbackReason ?? $strategyFallbackReason ?? 'no_optimized_strategy_matched';
            $fallbackReasonSource = $plannerFallbackReason !== null
                ? 'planner'
                : ($strategyFallbackReason !== null ? 'strategy' : 'executor');

            $legacyResult = $repository->executeLegacyAggregationPlan($plan, $fallbackReason);

            return $this->withExecutorMeta(
                $legacyResult,
                [
                    'executor_path_decision'          => 'legacy',
                    'optimized_attempted'             => $optimizedAttempted,
                    'optimized_candidate_count'       => count($candidateStrategies),
                    'executor_fallback_reason'        => $fallbackReason,
                    'executor_fallback_reason_source' => $fallbackReasonSource,
                ] + ($plannerDiagnostics !== [] ? ['planner_diagnostics' => $plannerDiagnostics] : [])
            );
        }

        /**
         * @param array<string, mixed> $meta
         */
        private function withExecutorMeta(AggregationExecutionResult $result, array $meta): AggregationExecutionResult
        {
            return new AggregationExecutionResult(
                rows: $result->getRows(),
                meta: array_merge($result->getMeta(), $meta),
            );
        }

        /**
         * @return array<string, mixed>
         */
        private function buildPlannerDiagnostics(AggregationPlan $plan): array
        {
            $stages = $plan->getStages();
            $profileStage = $stages['profiles'] ?? [];

            $diagnostics = [
                'fallback_reason'              => $plan->getFallbackReason(),
                'group_pattern'                => $stages['grouping']['normalized_pattern'] ?? null,
                'unsupported_filter_operators' => $stages['filters']['unsupported_operators'] ?? [],
                'missing_reducer_expressions'  => $stages['reducers']['missing_reducer_expressions'] ?? [],
                'profile_checked'              => $profileStage['checked'] ?? null,
                'profile_supported'            => $profileStage['supported'] ?? null,
                'profile_channel'              => $profileStage['channel'] ?? null,
                'profile_count'                => $profileStage['profile_count'] ?? null,
                'profile_failure_reason'       => $profileStage['failure_reason'] ?? null,
            ];

            return array_filter(
                $diagnostics,
                static fn(mixed $value): bool => $value !== null && $value !== []
            );
        }

        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @param array<int, array<string, mixed>> $rows
         * @param array<string, mixed> $meta
         */
        private function recordTelemetryEvent(
            BaseRepository $repository,
            array          $aggregations,
            array          $groupBy,
            ?object        $filters,
            ?string        $startDate,
            ?string        $endDate,
            array          $rows,
            array          $meta,
        ): void
        {
            try {
                $recorder = $this->telemetryRecorder ?? new AggregationTelemetryEventRecorder();
                if (!$recorder->isEnabled()) {
                    return;
                }

                $recorder->record([
                        'event_version'           => 1,
                        'recorded_at_utc'         => gmdate('c'),
                        'repository_class'        => $repository::class,
                        'entity_name'             => $repository->getClassName(),
                        'aggregation_aliases'     => array_values(array_keys($aggregations)),
                        'aggregation_expressions' => $aggregations,
                        'group_by'                => array_values($groupBy),
                        'filter_keys'             => $this->extractFilterKeys($filters),
                        'start_date'              => $startDate,
                        'end_date'                => $endDate,
                        'row_count'               => count($rows),
                    ] + $meta);
            } catch (\Throwable) {
                // Telemetry is best-effort and must not affect aggregate execution.
            }
        }

        /**
         * @return array<int, string>
         */
        private function extractFilterKeys(?object $filters): array
        {
            if ($filters === null) {
                return [];
            }

            $keys = [];
            foreach ($filters as $key => $_value) {
                $normalized = trim((string)$key);
                if ($normalized !== '' && !in_array($normalized, $keys, true)) {
                    $keys[] = $normalized;
                }
            }

            sort($keys);

            return $keys;
        }
    }

