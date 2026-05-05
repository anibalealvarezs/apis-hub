<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Doctrine\DBAL\Query\QueryBuilder;

    final readonly class LegacyAggregateExecutionContext
    {
        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @param array<string, mixed> $relationContext
         */
        public function __construct(
            private QueryBuilder    $queryBuilder,
            private AggregationPlan $plan,
            private array           $aggregations,
            private ?object         $filters,
            private ?string         $startDate,
            private ?string         $endDate,
            private array           $groupBy,
            private ?string         $orderBy,
            private ?string         $orderDir,
            private string          $entityName,
            private bool            $isMetric,
            private bool            $isPostgres,
            private array           $relationContext = [],
        )
        {
        }

        public function getQueryBuilder(): QueryBuilder
        {
            return $this->queryBuilder;
        }

        public function getPlan(): AggregationPlan
        {
            return $this->plan;
        }

        /**
         * @return array<string, string>
         */
        public function getAggregations(): array
        {
            return $this->aggregations;
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

        /**
         * @return array<int, string>
         */
        public function getGroupBy(): array
        {
            return $this->groupBy;
        }

        public function getOrderBy(): ?string
        {
            return $this->orderBy;
        }

        public function getOrderDir(): ?string
        {
            return $this->orderDir;
        }

        public function getEntityName(): string
        {
            return $this->entityName;
        }

        public function isMetric(): bool
        {
            return $this->isMetric;
        }

        public function isPostgres(): bool
        {
            return $this->isPostgres;
        }

        /**
         * @return array<string, mixed>
         */
        public function getRelationContext(): array
        {
            return $this->relationContext;
        }

        public function getRelationContextValue(string $key, mixed $default = null): mixed
        {
            return $this->relationContext[$key] ?? $default;
        }

        /**
         * @param array<string, mixed> $relationContext
         */
        public function withRelationContext(array $relationContext): self
        {
            return new self(
                queryBuilder: $this->queryBuilder,
                plan: $this->plan,
                aggregations: $this->aggregations,
                filters: $this->filters,
                startDate: $this->startDate,
                endDate: $this->endDate,
                groupBy: $this->groupBy,
                orderBy: $this->orderBy,
                orderDir: $this->orderDir,
                entityName: $this->entityName,
                isMetric: $this->isMetric,
                isPostgres: $this->isPostgres,
                relationContext: $relationContext,
            );
        }
    }

