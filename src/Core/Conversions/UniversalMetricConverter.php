<?php

declare(strict_types=1);

namespace Core\Conversions;

use Carbon\Carbon;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * UniversalMetricConverter
 * 
 * Standardizes the conversion of raw provider data into APIs Hub metric objects.
 * Uses a configuration-driven approach to map fields and dimensions.
 */
class UniversalMetricConverter
{
    /**
     * Converts raw rows into a collection of metric objects based on mapping config.
     *
     * @param array $rows Raw rows from the provider API
     * @param array $config Mapping configuration
     * @param LoggerInterface|null $logger
     * @return ArrayCollection
     */
    public static function convert(array $rows, array $config, ?LoggerInterface $logger = null): ArrayCollection
    {
        $startTime = microtime(true);
        $collection = new ArrayCollection();
        
        // Configuration defaults
        $channel = $config['channel'] ?? null;
        if (!$channel) {
            throw new \InvalidArgumentException("Channel is required for UniversalMetricConverter");
        }

        $period = $config['period'] ?? Period::Daily->value;
        $platformIdField = $config['platform_id_field'] ?? 'id';
        $dateField = $config['date_field'] ?? 'date';
        
        // Metric Mappings: [provider_field => system_name]
        $metricsMap = $config['metrics'] ?? [];
        
        // Dimension Mappings: [breakdown_key]
        $dimensionsKeys = $config['dimensions'] ?? [];

        // Contextual Entities (Page, Account, Campaign, etc.)
        $context = $config['context'] ?? [];

        foreach ($rows as $row) {
            // 1. Extract Dimensions
            $dimensions = [];
            foreach ($dimensionsKeys as $dimKey) {
                if (is_array($dimKey)) {
                    // Support pre-calculated dimensions (e.g. from GSC aggregation)
                    $dimensions[] = $dimKey;
                } elseif (isset($row[$dimKey])) {
                    $dimensions[] = [
                        'dimensionKey' => $dimKey,
                        'dimensionValue' => (string) $row[$dimKey]
                    ];
                }
            }
            // Sort to ensure stable hash
            KeyGenerator::sortDimensions($dimensions);
            $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);

            // 2. Extract Date
            $rawDate = self::getValueByPath($row, $dateField);
            $metricDate = $rawDate ? Carbon::parse((string) $rawDate)->toDateString() : Carbon::now()->toDateString();

            // 3. Nested Row Support
            $nestedRows = [$row];
            if (isset($config['row_path'])) {
                $nestedRows = self::getValueByPath($row, $config['row_path']) ?: [];
            }

            foreach ($nestedRows as $nRow) {
                // If nested, we might need to extract date from the nested row
                $nDate = $metricDate;
                if (isset($config['nested_date_field'])) {
                    $rawNDate = self::getValueByPath($nRow, $config['nested_date_field']);
                    if ($rawNDate) $nDate = Carbon::parse((string) $rawNDate)->toDateString();
                }

                // 4. Process each mapped metric
                foreach ($metricsMap as $providerField => $systemName) {
                    $rawValue = self::getValueByPath($nRow, $providerField);
                    if (is_null($rawValue) && !isset($config['include_nulls'])) {
                        continue;
                    }

                    $normalizedValue = self::normalizeValue($rawValue);

                    // 5. Generate Metric Configuration Key
                    $keyParams = array_merge($context, [
                        'channel' => $channel,
                        'name' => $systemName,
                        'period' => $period,
                        'dimensionSet' => $dimensionsHash,
                        'country' => $row['country'] ?? ($row['country_code'] ?? null),
                        'device' => $row['device'] ?? null,
                    ]);

                    $keyParams = array_filter($keyParams, function($v, $k) {
                        return !is_null($v) && in_array($k, [
                            'channel', 'name', 'period', 'account', 'channeledAccount', 'campaign',
                            'channeledCampaign', 'channeledAdGroup', 'channeledAd', 'creative',
                            'page', 'query', 'post', 'product', 'customer', 'order', 'country',
                            'device', 'dimensionSet'
                        ]);
                    }, ARRAY_FILTER_USE_BOTH);

                    $metricConfigKey = KeyGenerator::generateMetricConfigKey(...$keyParams);

                    // 6. Build Standardized Metric Object
                    $metric = new stdClass();
                    $metric->channel = $channel;
                    $metric->name = $systemName;
                    $metric->value = $normalizedValue;
                    $metric->period = $period;
                    $metric->metricDate = $nDate;
                    $metric->platformId = (string) ($row[$platformIdField] ?? $config['fallback_platform_id'] ?? 'unknown');
                    $metric->platformCreatedAt = $nDate;
                    $metric->dimensions = $dimensions;
                    $metric->dimensionsHash = $dimensionsHash;
                    $metric->metricConfigKey = $metricConfigKey;
                    $metric->data = $row;
                    $metric->nested_data = $nRow;

                    // Metadata filtering (optional)
                    $metadataFields = $config['metadata_fields'] ?? [];
                    if (!empty($metadataFields)) {
                        $metric->metadata = array_filter($row, fn($key) => in_array($key, $metadataFields), ARRAY_FILTER_USE_KEY);
                    } else {
                        $metric->metadata = [];
                    }
                    
                    // Inject Context Values (mostly strings for KeyGenerator reference)
                    foreach ($context as $key => $val) {
                        $metric->$key = $val;
                    }

                    // Inject Entities (objects for MetricsProcessor)
                    $entities = $config['entities'] ?? [];
                    foreach ($entities as $key => $val) {
                        $metric->$key = $val;
                    }

                    $collection->add($metric);
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $logger?->debug(sprintf(
            "Universal conversion completed: %d source rows -> %d metrics in %.4f seconds",
            count($rows),
            $collection->count(),
            $totalTime
        ));

        return $collection;
    }

    /**
     * Extracts values using dot-notation path.
     */
    private static function getValueByPath(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }

    /**
     * Normalizes complex provider values (arrays, strings) into numeric formats.
     */
    private static function normalizeValue(mixed $value): float|int
    {
        if (is_numeric($value)) {
            return $value + 0;
        }

        if (is_array($value)) {
            // Handle Meta-style action results or value objects
            return (float) ($value[0]['value'] ?? ($value[0]['amount'] ?? ($value['value'] ?? 0)));
        }

        return 0;
    }
}
