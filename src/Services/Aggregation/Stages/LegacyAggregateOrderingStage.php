<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateOrderingStage
    {
        public function apply(LegacyAggregateExecutionContext $context): void
        {
            $qb = $context->getQueryBuilder();
            $orderBy = $context->getOrderBy();
            $orderDir = $context->getOrderDir();

            if (!$orderBy) {
                return;
            }

            $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $qb->orderBy($orderBy, $direction);
        }
    }

