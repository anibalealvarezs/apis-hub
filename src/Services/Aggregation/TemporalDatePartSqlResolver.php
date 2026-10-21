<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class TemporalDatePartSqlResolver
{
    public function resolve(string $field, string $baseDate, bool $isPostgres): ?string
    {
        $lowerField = strtolower(trim($field));

        if ($isPostgres) {
            $dateParts = [
                'year' => "EXTRACT(YEAR FROM $baseDate)",
                'month' => "EXTRACT(MONTH FROM $baseDate)",
                'day' => "EXTRACT(DAY FROM $baseDate)",
                'week' => "EXTRACT(WEEK FROM $baseDate)",
                'quarter' => "EXTRACT(QUARTER FROM $baseDate)",
                'dayofweek' => "EXTRACT(DOW FROM $baseDate)",
                'dayname' => "TO_CHAR($baseDate, 'Day')",
                'monthname' => "TO_CHAR($baseDate, 'Month')",
                'daily' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'weekly' => "TO_CHAR($baseDate, 'IYYY-\"W\"IW')",
                'monthly' => "TO_CHAR($baseDate, 'YYYY-MM')",
                'quarterly' => "CONCAT(EXTRACT(YEAR FROM $baseDate), '-Q', EXTRACT(QUARTER FROM $baseDate))",
                'semiannual' => "CONCAT(EXTRACT(YEAR FROM $baseDate), '-S', CASE WHEN EXTRACT(MONTH FROM $baseDate) <= 6 THEN 1 ELSE 2 END)",
                'yearly' => "EXTRACT(YEAR FROM $baseDate)",
                'annually' => "EXTRACT(YEAR FROM $baseDate)",
                'trailing_weekly' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'trailing_monthly' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'trailing_quarterly' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'trailing_semiannual' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'trailing_annually' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'trailing_yearly' => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
            ];

            return $dateParts[$lowerField] ?? null;
        }

        $dateParts = [
            'year' => "YEAR($baseDate)",
            'month' => "MONTH($baseDate)",
            'day' => "DAY($baseDate)",
            'week' => "WEEK($baseDate)",
            'quarter' => "QUARTER($baseDate)",
            'dayofweek' => "DAYOFWEEK($baseDate)",
            'dayname' => "DAYNAME($baseDate)",
            'monthname' => "MONTHNAME($baseDate)",
            'daily' => "DATE($baseDate)",
            'weekly' => "CONCAT(YEAR($baseDate), '-W', LPAD(WEEK($baseDate), 2, '0'))",
            'monthly' => "CONCAT(YEAR($baseDate), '-', LPAD(MONTH($baseDate), 2, '0'))",
            'quarterly' => "CONCAT(YEAR($baseDate), '-Q', QUARTER($baseDate))",
            'semiannual' => "CONCAT(YEAR($baseDate), '-S', IF(MONTH($baseDate) <= 6, 1, 2))",
            'yearly' => "YEAR($baseDate)",
            'annually' => "YEAR($baseDate)",
            'trailing_weekly' => "DATE($baseDate)",
            'trailing_monthly' => "DATE($baseDate)",
            'trailing_quarterly' => "DATE($baseDate)",
            'trailing_semiannual' => "DATE($baseDate)",
            'trailing_annually' => "DATE($baseDate)",
            'trailing_yearly' => "DATE($baseDate)",
        ];

        return $dateParts[$lowerField] ?? null;
    }
}

