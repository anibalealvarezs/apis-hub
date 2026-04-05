<?php

namespace Services;

use Helpers\Helpers;
use Throwable;

class HealthService
{
    /**
     * Performs a deep health check of the system.
     */
    public static function getFullHealthReport(): array
    {
        $dbStatus = true;
        $redisStatus = true;
        $catalogStats = [];
        $channelsHealth = ['facebook' => true, 'google' => true];

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
        try {
            $channels = Helpers::getChannelsConfig();
            if (empty($channels['facebook']['graph_user_access_token'])) $channelsHealth['facebook'] = false;
            if (empty($channels['google_search_console']['token'])) $channelsHealth['google'] = false;
        } catch (Throwable $e) {
            $channelsHealth['facebook'] = false;
            $channelsHealth['google'] = false;
        }

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
