<?php

namespace Helpers\AssetsPatternsHelpers;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;

class PagePatternsHelper
{
    /**
     * @throws \Exception
     */
    public static function getPlatformId(array $asset, array $pattern, object $driver): string {
        $rawPlatformId = match($pattern['platform_id']['type']) {
            'md5' => md5($asset[$pattern['platform_id']['key']]),
            default => $asset[$pattern['platform_id']['key']]
        };
        if (!method_exists($driver, 'getPlatformId')) {
            throw new \Exception("Driver must implement getPlatformId method to use platform_id patterns.");
        }
        $platformId = $driver->getPlatformId($rawPlatformId);
        if (method_exists($driver, 'getCleanId')) {
            $platformId = $driver->getCleanId($rawPlatformId);
        } else {
            $platformId = $rawPlatformId;
        }

        return $platformId;
    }

    public static function getHostname(array $asset, array $pattern, SyncDriverInterface $driver): ?string {
        return method_exists($driver, 'getCleanHostname') ? $driver->getCleanHostname($asset[$pattern['hostname_key']]) : ($asset[$pattern['hostname_key']] ?? null);
    }

    public static function getTitle(array $asset, array $pattern): ?string {
        return $asset[$pattern['title_key']] ?? null;
    }

    public static function getUrl(array $asset, array $pattern): ?string {
        return match($pattern['url']['type']) {
            'custom' => $pattern['url']['prefix'] . $asset[$pattern['url']['key']],
            default => $asset['url'] ?? null
        };
    }

    public static function getCanonicalId(array $asset, array $pattern, ?string $platformId, ?string $hostname): ?string {
        $canSource = $pattern['canonical_id']['field'];
        $canValue = match($canSource) {
            'platformId' => $platformId,
            'hostname' => $hostname,
            default => $asset[$canSource] ?? null
        };

        if (empty($canValue)) {
            return null;
        }

        return $pattern['canonical_id']['prefix'] . ':' . $canValue;
    }

    public static function getData(array $asset, array $pattern): ?array {
        return $asset[$pattern['data_key']] ?? null;
    }
}