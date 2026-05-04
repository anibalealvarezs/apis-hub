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
        private readonly array $aggregations,
        private readonly array $groupBy = [],
        private readonly ?object $filters = null,
        private readonly ?string $startDate = null,
        private readonly ?string $endDate = null,
        private readonly ?string $orderBy = null,
        private readonly ?string $orderDir = 'ASC',
        private readonly string $preferredExecutionPath = 'optimized',
        private readonly bool $canUseOptimized = true,
        private readonly ?string $fallbackReason = null,
        private readonly array $context = [],
        private readonly array $stages = [],
        private readonly array $candidateOptimizedStrategies = [],
    ) {
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

