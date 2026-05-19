<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class MetricPeriodConditionSqlResolver
{
    public function resolve(?string $requestedPeriod, bool $isPostgres, string $defaultPeriod = 'daily'): string
    {
        $period = strtolower(trim((string)$requestedPeriod));
        if ($period === '' || preg_match('/^[a-z0-9_]+$/', $period) !== 1) {
            $period = strtolower($defaultPeriod);
        }

        return $isPostgres
            ? "LOWER(mc.period) = '{$period}'"
            : "mc.period = '{$period}'";
    }
}

