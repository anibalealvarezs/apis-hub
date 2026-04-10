<?php

namespace Services;

use Core\Drivers\DriverFactory;

/**
 * Service to manage the master schemas for every channel in APIs Hub.
 * This ensures consistency across deployments and simplified configuration management.
 */
class ConfigSchemaRegistryService
{
    /**
     * Default schema for any single metric.
     */
    private const METRIC_BASE_SCHEMA = [
        'enabled'             => false,
        'format'              => 'number',
        'precision'           => 0,
        'sparkline'           => false,
        'sparkline_direction' => 'standard',
        'sparkline_color'     => null,
        'conditional'         => ['enabled' => false, 'config' => []]
    ];

    /**
     * Hydrates a configuration array with the default schema for a specific channel and section.
     */
    public static function hydrate(string $channel, string $section, array $currentConfig): array
    {
        try {
            $driver = DriverFactory::get($channel);
            $fullSchema = $driver->getConfigSchema();
            $schema = $fullSchema[$section] ?? [];
            
            if ($section === 'metrics') {
                return self::hydrateMetrics($channel, $currentConfig, $fullSchema);
            }

            return array_replace_recursive($schema, $currentConfig);
        } catch (\Exception $e) {
            return $currentConfig;
        }
    }

    /**
     * Specific hydration for metrics lists.
     */
    private static function hydrateMetrics(string $channel, array $currentConfig, array $fullSchema): array
    {
        $hydrated = [];
        $knownMetrics = $fullSchema['metrics'] ?? [];
        
        foreach ($knownMetrics as $metric => $defaults) {
            $userConfig = $currentConfig[$metric] ?? [];
            $fullDefaults = array_merge(self::METRIC_BASE_SCHEMA, $defaults);
            $hydrated[$metric] = array_replace_recursive($fullDefaults, $userConfig);
        }
        
        return $hydrated;
    }

    /**
     * Get default entity structure for new entity creation.
     */
    public static function getEntitySchema(string $channel, array $overrides = []): array
    {
        try {
            $driver = DriverFactory::get($channel);
            $fullSchema = $driver->getConfigSchema();
            $schema = $fullSchema['entity'] ?? [];
            return array_merge($schema, $overrides);
        } catch (\Exception $e) {
            return $overrides;
        }
    }
}
