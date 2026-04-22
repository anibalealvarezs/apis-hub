<?php

namespace Helpers\AssetsPatternsHelpers;

class ChanneledAccountPatternsHelper
{
    public static function getPlatformId(array $asset, array $pattern, object $driver): string {
        $rawPlatformId = match($pattern['platform_id']['type']) {
            'md5' => md5($asset[$pattern['platform_id']['key']]),
            default => $asset[$pattern['platform_id']['key']]
        };
        if (method_exists($driver, 'getCleanId')) {
            $platformId = $driver->getCleanId($rawPlatformId);
        } else {
            $platformId = $rawPlatformId;
        }

        return $platformId;
    }

    public static function getPlatformCreatedAt(array $asset, array $pattern): ?string {
        return $asset[$pattern['platform_created_at_key']] ?? null;
    }

    public static function getType(array $pattern): string {
        return $pattern['type'];
    }

    public static function getName(array $asset, array $pattern): ?string {
        return $asset[$pattern['name_key']] ?? null;
    }

    public static function getData(array $asset, array $pattern): ?array {
        return $asset[$pattern['data_key']] ?? [];
    }
}