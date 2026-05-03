<?php

declare(strict_types=1);

namespace Core\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * UniversalEntityConverter
 * 
 * A configuration-driven engine for standardizing provider-specific entities
 * (Orders, Products, Customers, etc.) into system-standard objects.
 */
class UniversalEntityConverter
{
    /**
     * Converts a collection of raw provider rows into standardized entities.
     * 
     * @param array $rows The raw data rows from the provider API.
     * @param array $config Configuration mapping for conversion.
     * @param LoggerInterface|null $logger Optional logger for debugging.
     * @return ArrayCollection A collection of standardized entity objects.
     */
    public static function convert(array $rows, array $config, ?LoggerInterface $logger = null): ArrayCollection
    {
        $collection = new ArrayCollection();
        $channel = $config['channel'] ?? 'unknown';
        $mapping = $config['mapping'] ?? [];
        $platformIdField = $config['platform_id_field'] ?? 'id';
        $dateField = $config['date_field'] ?? 'created_at';
        
        foreach ($rows as $row) {
            // error_log("CELL_DATA: " . json_encode($row));
            $entity = new stdClass();
            $entity->channel = $channel;
            
            // 1. Mandatory Fields
            $entity->platformId = (string) (self::getNestedValue($row, $platformIdField) ?? '');
            
            $rawDate = self::getNestedValue($row, $dateField);
            $entity->platformCreatedAt = $rawDate ? Carbon::parse($rawDate) : null;

            // 2. Dynamic Mapping
            foreach ($mapping as $key => $path) {
                if (is_callable($path)) {
                    $entity->{$key} = $path($row);
                } else {
                    $entity->{$key} = self::getNestedValue($row, $path);
                }
            }

            // 3. Inject Contextual Entities/IDs
            if (isset($config['context'])) {
                foreach ($config['context'] as $key => $value) {
                    $entity->{$key} = $value;
                }
            }

            // 4. Raw Data Preservation
            if (!isset($entity->data)) {
                $entity->data = $row;
            }

            $collection->add($entity);
        }

        return $collection;
    }

    /**
     * Extracts a value from a nested array using dot notation.
     */
    private static function getNestedValue(array $data, string|array $path): mixed
    {
        if (is_array($path)) {
            return $path; // Explicit value fallback
        }

        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !isset($data[$key])) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }
}
