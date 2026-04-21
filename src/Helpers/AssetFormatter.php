<?php

namespace Helpers;

use Exception;

class AssetFormatter
{
    /**
     * @throws Exception
     */
    public static function formatChanneledAccountSyncData(array $asset, array $pattern, $driver): ?array
    {
        $config = $pattern['channeled_account'] ?? null;
        if (! $config) {
            return null;
        }

        $platformId = self::calculatePlatformId(asset: $asset, patternConfig: $config, driver: $driver);
        $name = self::calculateName(asset: $asset, config: $config);
        $platformCreatedAt = self::calculatePlatformCreatedAt(asset: $asset, config: $config);
        $data = self::calculateData(asset: $asset, config: $config);
        $type = $config['type'];

        if (empty($platformId)) {
            return null;
        }

        return [
            'platformId' => $platformId,
            'type' => $type,
            'name' => $name,
            'platformCreatedAt' => is_string($platformCreatedAt) ? new \DateTime($platformCreatedAt) : null,
            'data' => $data,
        ];
    }

    /**
     * @throws Exception
     */
    public static function formatPageSyncData(array $asset, array $pattern, $driver): ?array
    {
        $config = $pattern['page'] ?? null;
        if (! $config) {
            return null;
        }

        $platformId = self::calculatePlatformId(asset: $asset, patternConfig: $config, driver: $driver);
        $hostname = self::calculateHostname(asset: $asset, config: $config, driver: $driver);
        $title = self::calculateTitle(asset: $asset, config: $config);
        $url = self::calculateUrl(asset: $asset, config: $config);
        $data = self::calculateData(asset: $asset, config: $config);

        $canSource = $config['canonical_id']['field'];
        $canValue = match($canSource) {
            'platformId' => $platformId,
            'hostname' => $hostname,
            default => $asset[$canSource] ?? null
        };

        if (empty($canValue)) {
            return null;
        }

        $canonicalId = $config['canonical_id']['preffix'] . ':' . $canValue;

        return [
            'platformId' => $platformId,
            'hostname' => $hostname,
            'title' => $title,
            'url' => $url,
            'canonicalId' => $canonicalId,
            'data' => $data,
        ];
    }

    private static function calculatePlatformId(array $asset, array $patternConfig, $driver): ?string
    {
        $key = $patternConfig['platform_id']['key'];
        $val = $asset[$key] ?? null;
        if (! $val) {
            return null;
        }

        $rawId = match($patternConfig['platform_id']['type']) {
            'md5' => md5($val),
            default => $val
        };

        return method_exists($driver, 'getCleanId') ? $driver->getCleanId($rawId) : $rawId;
    }

    private static function calculateHostname(array $asset, array $config, $driver): ?string
    {
        $val = $asset[$config['hostname_key']] ?? null;

        return method_exists($driver, 'getCleanHostname') ? $driver->getCleanHostname($val) : $val;
    }

    private static function calculateName(array $asset, array $config): ?string
    {
        return $asset[$config['name_key']] ?? null;
    }

    private static function calculateTitle(array $asset, array $config): ?string
    {
        return $asset[$config['title_key']] ?? null;
    }

    private static function calculateUrl(array $asset, array $config): ?string
    {
        return match($config['url']['type']) {
            'custom' => $config['url']['preffix'] . $asset[$config['url']['key']],
            default => $asset['url'] ?? null
        };
    }

    private static function calculatePlatformCreatedAt(array $asset, array $config): ?string
    {
        return $asset[$config['platform_created_at_key']] ?? null;
    }

    private static function calculateData(array $asset, array $config): array
    {
        return $asset[$config['data_key']] ?? [];
    }
}
