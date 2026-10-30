<?php

namespace Services;

use Helpers\Helpers;
use Throwable;

class HealthService
{
    public static function getMcpEndpoint(?bool $container = null, ?array $environment = null): array
    {
        $container ??= file_exists('/.dockerenv');
        $environment ??= ['MCP_HOST' => getenv('MCP_HOST'), 'MCP_PORT' => getenv('MCP_PORT'), 'MCP_INTERNAL_PORT' => getenv('MCP_INTERNAL_PORT')];
        return [
            'host' => ($environment['MCP_HOST'] ?? null) ?: ($container ? 'mcp' : '127.0.0.1'),
            'port' => (int)(($container ? ($environment['MCP_INTERNAL_PORT'] ?? null) : ($environment['MCP_PORT'] ?? null)) ?: 3000),
        ];
    }

    public static function heartbeatUrl(string $url): string
    {
        $url = rtrim($url, '/');
        return str_ends_with($url, '/api/heartbeat') ? $url : $url.'/api/heartbeat';
    }

    /**
     * Performs a deep health check of the system.
     */
    public static function getFullHealthReport(): array
    {
        $dbStatus = true;
        $redisStatus = true;
        $catalogStats = [];

        // 1. DB
        try {
            $em = Helpers::getManager();
            $em->getConnection()->connect();
        } catch (Throwable $e) {
            $dbStatus = false;
        }

        // 2. Redis
        try {
            $redis = Helpers::getRedisClient();
            $redis->ping();
        } catch (Throwable $e) {
            $redisStatus = false;
        }

        // 3. Catalog
        try {
            if ($dbStatus) {
                $db = Helpers::getManager()->getConnection();
                $catalogStats = [
                    'accounts' => (int)$db->fetchOne("SELECT COUNT(*) FROM channeled_accounts"),
                    'pages' => (int)$db->fetchOne("SELECT COUNT(*) FROM pages"),
                    'campaigns' => (int)$db->fetchOne("SELECT COUNT(*) FROM campaigns"),
                    'jobs' => (int)$db->fetchOne("SELECT COUNT(*) FROM jobs"),
                ];
            }
        } catch (Throwable $e) {}

        // 4. Channels
        $channelsHealth = [];
        try {
            $availableChannels = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels();
            foreach ($availableChannels as $channel) {
                try {
                    $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel);
                    $authProvider = $driver->getAuthProvider();
                    $channelsHealth[$channel] = $authProvider ? $authProvider->isValid() : false;
                } catch (Throwable $e) {
                    $channelsHealth[$channel] = false;
                }
            }

        } catch (Throwable $e) {}

        return [
            'status' => ($dbStatus && $redisStatus) ? 'online' : 'error',
            'db' => $dbStatus,
            'redis' => $redisStatus,
            'catalog' => $catalogStats,
            'channels' => $channelsHealth,
            'system' => [
                'php' => PHP_VERSION,
                'os' => PHP_OS,
                'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'uptime' => trim(@shell_exec('uptime -p 2>/dev/null') ?: 'unknown'),
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Performs a lightweight status check of the system infrastructure.
     */
    public static function getInfraStatus(): array
    {
        return [
            'project_name' => getenv('PROJECT_NAME'),
            'deployment_name' => getenv('DEPLOYMENT_NAME'),
            'php_version' => PHP_VERSION,
            'uptime' => trim(@shell_exec('uptime -p 2>/dev/null') ?: 'unknown'),
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'os' => PHP_OS,
            'server_time' => date('Y-m-d H:i:s'),
            'instances_configured' => file_exists(__DIR__ . '/../../config/instances.yaml'),
        ];
    }
}
