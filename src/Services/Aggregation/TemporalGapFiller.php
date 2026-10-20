<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use DateInterval;
    use DateTime;
    use Exception;

    final class TemporalGapFiller
    {
        /**
         * Fills gaps in a time series result set with zeroed-out records.
         *
         * @param array<int, array<string, mixed>> $results
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @return array<int, array<string, mixed>>
         * @throws Exception
         */
        public function fill(
            array  $results,
            string $temporalField,
            string $type,
            string $startDate,
            string $endDate,
            array  $aggregations,
            array  $groupBy
        ): array
        {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $periods = [];

            if (str_starts_with($type, 'trailing_')) {
                // Trailing/rolling intervals step backward from $end down to $start
                $stepInterval = match ($type) {
                    'trailing_weekly' => 'P1W',
                    'trailing_monthly' => 'P1M',
                    'trailing_quarterly' => 'P3M',
                    'trailing_semiannual' => 'P6M',
                    'trailing_annually', 'trailing_yearly' => 'P1Y',
                    default => 'P1Y',
                };
                $trailingPeriods = [];
                $cursor = clone $end;
                while (true) {
                    $pKey = match ($type) {
                        'trailing_weekly' => 'Trailing 7D (to ' . $cursor->format('M d') . ')',
                        'trailing_monthly' => 'Trailing 30D (to ' . $cursor->format('M d') . ')',
                        'trailing_quarterly' => 'Trailing 90D (to ' . $cursor->format('M d') . ')',
                        'trailing_semiannual' => 'Trailing 180D (to ' . $cursor->format('M d') . ')',
                        'trailing_annually', 'trailing_yearly' => 'TTM (to ' . $cursor->format('M d, Y') . ')',
                        default => 'Trailing (to ' . $cursor->format('Y-m-d') . ')',
                    };
                    $trailingPeriods[$pKey] = true;
                    $prev = clone $cursor;
                    $cursor->sub(new DateInterval($stepInterval));
                    if ($prev <= $start) {
                        break;
                    }
                }
                // Reverse so chronological order (oldest to newest) is maintained
                $periods = array_reverse($trailingPeriods, true);
            } else {
                $current = clone $start;
                while ($current <= $end) {
                    $periodKey = match ($type) {
                        'weekly' => $current->format('Y-\\W').str_pad($current->format('W'), 2, '0', STR_PAD_LEFT),
                        'monthly' => $current->format('Y-m'),
                        'quarterly' => $current->format('Y-\\Q').(string)ceil((int)$current->format('n') / 3),
                        'semiannual' => $current->format('Y-\\S').((int)$current->format('n') <= 6 ? '1' : '2'),
                        'yearly', 'annually' => $current->format('Y'),
                        default => $current->format('Y-m-d'),
                    };
                    $periods[$periodKey] = true;

                    $interval = match ($type) {
                        'weekly' => 'P1W',
                        'monthly' => 'P1M',
                        'quarterly' => 'P3M',
                        'semiannual' => 'P6M',
                        'yearly', 'annually' => 'P1Y',
                        default => 'P1D',
                    };
                    $current->add(new DateInterval($interval));
                }
            }

            $otherGroups = array_values(array_filter($groupBy, static fn(string $f): bool => $f !== $temporalField));

            if ($otherGroups !== []) {
                $uniqueCombos = [];
                foreach ($results as $row) {
                    $combo = [];
                    foreach ($otherGroups as $field) {
                        $val = $row[$field] ?? $row[strtolower($field)] ?? null;
                        $combo[$field] = $val;
                    }
                    $comboKey = serialize($combo);
                    $uniqueCombos[$comboKey] = $combo;
                }

                $indexedResults = [];
                foreach ($results as $row) {
                    $combo = [];
                    foreach ($otherGroups as $field) {
                        $val = $row[$field] ?? $row[strtolower($field)] ?? null;
                        $combo[$field] = $val;
                    }
                    $temporalVal = $row[$temporalField] ?? $row[strtolower($temporalField)] ?? null;
                    $key = $temporalVal.'|'.serialize($combo);
                    $indexedResults[$key] = $row;
                }

                $finalResults = [];
                foreach ($uniqueCombos as $combo) {
                    foreach (array_keys($periods) as $pKey) {
                        $lookupKey = $pKey.'|'.serialize($combo);
                        if (isset($indexedResults[$lookupKey])) {
                            $finalResults[] = $indexedResults[$lookupKey];
                        } else {
                            $newRow = array_merge($combo, [$temporalField => $pKey]);
                            foreach (array_keys($aggregations) as $alias) {
                                $newRow[$alias] = 0;
                            }
                            $finalResults[] = $newRow;
                        }
                    }
                }

                return $finalResults;
            }

            $indexedResults = [];
            foreach ($results as $row) {
                $temporalVal = $row[$temporalField] ?? $row[strtolower($temporalField)] ?? null;
                $indexedResults[(string)$temporalVal] = $row;
            }

            $finalResults = [];
            foreach (array_keys($periods) as $pKey) {
                if (isset($indexedResults[$pKey])) {
                    $finalResults[] = $indexedResults[$pKey];
                } else {
                    $newRow = [$temporalField => $pKey];
                    foreach (array_keys($aggregations) as $alias) {
                        $newRow[$alias] = 0;
                    }
                    $finalResults[] = $newRow;
                }
            }

            return $finalResults;
        }
    }

