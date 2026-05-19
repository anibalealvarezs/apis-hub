<?php

    declare(strict_types=1);

    namespace Interfaces;

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Exception;
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
         * @throws Exception
         */
        public function execute(
            Connection      $connection,
            AggregationPlan $plan,
            bool            $isPostgres
        ): ?array;
    }
