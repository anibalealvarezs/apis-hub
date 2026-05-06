<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    final class AggregationPlan
    {
        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @param array<string, mixed> $context
         * @param array<string, mixed> $stages
         * @param array<int, string> $candidateOptimizedStrategies
         */
        public function __construct(
            private array   $aggregations,
            private array   $groupBy = [],
            private ?object $filters = null,
            private ?string $startDate = null,
            private ?string $endDate = null,
            private ?string $orderBy = null,
            private ?string $orderDir = 'ASC',
            private string  $preferredExecutionPath = 'optimized',
            private bool    $canUseOptimized = true,
            private ?string $fallbackReason = null,
            private array   $context = [],
            private array   $stages = [],
            private array   $candidateOptimizedStrategies = [],
        )
        {
        }

        /**
         * @return array<string, string>
         */
        public function getAggregations(): array
        {
            return $this->aggregations;
        }

        /**
         * @return array<int, string>
         */
        public function getGroupBy(): array
        {
            return $this->groupBy;
        }

        public function getFilters(): ?object
        {
            return $this->filters;
        }

        public function getStartDate(): ?string
        {
            return $this->startDate;
        }

        public function getEndDate(): ?string
        {
            return $this->endDate;
        }

        public function getOrderBy(): ?string
        {
            return $this->orderBy;
        }

        public function getOrderDir(): ?string
        {
            return $this->orderDir;
        }

        public function getPreferredExecutionPath(): string
        {
            return $this->preferredExecutionPath;
        }

        public function canUseOptimized(): bool
        {
            return $this->canUseOptimized;
        }

        public function getFallbackReason(): ?string
        {
            return $this->fallbackReason;
        }

        /**
         * @return array<string, mixed>
         */
        public function getContext(): array
        {
            return $this->context;
        }

        public function getContextValue(string $key, mixed $default = null): mixed
        {
            return $this->context[$key] ?? $default;
        }

        public function addContext(string $key, mixed $value): self
        {
            $this->context[$key] = $value;

            return $this;
        }

        /**
         * @return array<string, mixed>
         */
        public function getStages(): array
        {
            return $this->stages;
        }

        /**
         * @return array<string, mixed>
         */
        public function getStage(string $name): array
        {
            $stage = $this->stages[$name] ?? [];

            return is_array($stage) ? $stage : [];
        }

        public function getStageValue(string $stage, string $key, mixed $default = null): mixed
        {
            $stageData = $this->getStage($stage);

            return $stageData[$key] ?? $default;
        }

        /**
         * @return array<int, string>
         */
        public function getCandidateOptimizedStrategies(): array
        {
            return $this->candidateOptimizedStrategies;
        }
    }

