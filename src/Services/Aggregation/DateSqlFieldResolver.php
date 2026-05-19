<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class DateSqlFieldResolver
{
    /**
     * Resolve the base SQL date column used by temporal virtual fields.
     *
     * @param callable(string): bool $hasEntityField
     */
    public function resolveBaseDateSql(
        bool $isChanneledMetric,
        bool $isMetric,
        callable $hasEntityField
    ): string {
        if ($isChanneledMetric) {
            return 'm.metric_date';
        }

        if ($isMetric) {
            return 'e.metric_date';
        }

        if ($hasEntityField('platformCreatedAt')) {
            return 'e.platform_created_at';
        }

        return $hasEntityField('createdAt') ? 'e.created_at' : 'e.date';
    }
}

