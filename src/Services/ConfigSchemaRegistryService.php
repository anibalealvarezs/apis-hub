<?php

namespace Services;

/**
 * Service to manage the master schemas for every channel in APIs Hub.
 * This ensures consistency across deployments and simplified configuration management.
 */
class ConfigSchemaRegistryService
{
    /**
     * Master schema definitions for all available channels.
     * When adding a new channel (e.g., Klaviyo, Shopify), add its schema here.
     */
    private const SCHEMAS = [
        'google_search_console' => [
            'global' => [
                'enabled' => false,
                'cache_history_range' => '16 months',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'url' => '',
                'title' => '',
                'hostname' => '',
                'enabled' => true,
                'target_countries' => [],
                'target_keywords' => [],
                'include_keywords' => [],
                'exclude_keywords' => [],
                'include_countries' => [],
                'exclude_countries' => [],
                'include_pages' => [],
                'exclude_pages' => [],
            ],
            'metrics' => [
                'clicks' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                'impressions' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                'ctr' => ['enabled' => true, 'format' => 'percent', 'precision' => 2],
                'position' => ['enabled' => true, 'format' => 'number', 'precision' => 1, 'sparkline_direction' => 'inverted'],
            ]
        ],
        'facebook_marketing' => [
            'global' => [
                'enabled' => true,
                'cache_history_range' => '2 years',
                'cache_aggregations' => false,
                'metrics_strategy' => 'default',
            ],
            'entity' => [
                'id' => '',
                'name' => '',
                'enabled' => true,
                'exclude_from_caching' => false,
            ],
            'metrics' => [
                'spend' => ['enabled' => false, 'format' => 'currency', 'precision' => 2],
                'clicks' => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                'impressions' => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                'reach' => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                'frequency' => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                'ctr' => ['enabled' => false, 'format' => 'percent', 'precision' => 2],
                'cpc' => ['enabled' => false, 'format' => 'currency', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                'cpm' => ['enabled' => false, 'format' => 'currency', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                'results' => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                'cost_per_result' => ['enabled' => false, 'format' => 'currency', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                'result_rate' => ['enabled' => false, 'format' => 'percent', 'precision' => 2],
                'purchase_roas' => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                'actions' => ['enabled' => false, 'format' => 'number', 'precision' => 0],
            ]
        ],
        'facebook_organic' => [
            'global' => [
                'enabled' => true,
                'cache_history_range' => '2 years',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'id' => '',
                'url' => '',
                'title' => '',
                'hostname' => '',
                'enabled' => true,
                'exclude_from_caching' => false,
                'ig_account' => null,
                'ig_account_name' => null,
                'ig_accounts' => false,
                'page_metrics' => true,
                'posts' => true,
                'post_metrics' => false,
                'ig_account_metrics' => false,
                'ig_account_media' => false,
                'ig_account_media_metrics' => false,
            ]
        ]
    ];

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
        $schema = self::SCHEMAS[$channel][$section] ?? [];
        
        if ($section === 'metrics') {
            return self::hydrateMetrics($channel, $currentConfig);
        }

        return array_replace_recursive($schema, $currentConfig);
    }

    /**
     * Specific hydration for metrics lists.
     */
    private static function hydrateMetrics(string $channel, array $currentConfig): array
    {
        $hydrated = [];
        $knownMetrics = self::SCHEMAS[$channel]['metrics'] ?? [];
        
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
        $schema = self::SCHEMAS[$channel]['entity'] ?? [];
        return array_merge($schema, $overrides);
    }
}
