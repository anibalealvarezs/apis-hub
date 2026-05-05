<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Doctrine\DBAL\Connection;
use Services\Aggregation\AggregationPlan;

interface OptimizedAggregationStrategyInterface
{
    /**
     * Get the unique key for this strategy.
     */
    public function getKey(): string;

    /**
     * Execute the optimized aggregation.
     *
     * @return array<int, array<string, mixed>>|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function execute(
        Connection $connection,
        AggregationPlan $plan,
        bool $isPostgres
    ): ?array;
}
