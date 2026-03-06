<?php

namespace Services;

use DateTime;
use Exception;

class DateResolver
{
    /**
     * Resolves relative date strings (e.g. 'yesterday', 'today', '-3 days')
     * into 'YYYY-MM-DD' formatted strings.
     *
     * @param mixed $value
     * @return string
     */
    public static function resolve(mixed $value): string
    {
        if (!is_string($value)) {
            return (string) $value;
        }

        // If it's already a perfect YYYY-MM-DD, return it
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        try {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return $value; // Return original if not parsable as relative date
            }

            return date('Y-m-d', $timestamp);
        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * Resolves all date-like keys in an array of parameters.
     *
     * @param array $params
     * @return array
     */
    public static function resolveParams(array $params): array
    {
        $dateKeys = ['startDate', 'endDate', 'start_date', 'end_date', 'date'];

        foreach ($params as $key => $value) {
            if (in_array($key, $dateKeys, true)) {
                $params[$key] = self::resolve($value);
            }
        }

        return $params;
    }
}
