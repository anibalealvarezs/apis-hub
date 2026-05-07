<?php

declare(strict_types=1);

namespace Services\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Channel;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Services\CacheService;
use Throwable;

class SyncTelemetryService
{
    private EntityManagerInterface $entityManager;
    private CacheService $cacheService;
    private const CACHE_PREFIX = 'sync_telemetry:';
    private const DEFAULT_TTL = 86400; // 24 hours

    public function __construct(EntityManagerInterface $entityManager, CacheService $cacheService)
    {
        $this->entityManager = $entityManager;
        $this->cacheService = $cacheService;
    }

    /**
     * Get synchronization status for a channel or globally
     *
     * @param string|null $channelName
     * @param string|null $accountId
     * @return array
     */
    public function getSyncStatus(?string $channelName = null, ?string $accountId = null): array
    {
        if ($channelName) {
            return $this->getChannelStatus($channelName, $accountId);
        }

        return $this->getGlobalStatus();
    }

    /**
     * Get global platform sync status
     *
     * @return array
     */
    public function getGlobalStatus(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'global';
        
        return $this->cacheService->get($cacheKey, function () {
            $channelsConfig = Helpers::getChannelsConfig();
            $enabledChannels = array_keys(array_filter($channelsConfig, fn($c) => ($c['enabled'] ?? true)));
            
            $results = [];
            $totalCompletion = 0;
            $count = 0;

            foreach ($enabledChannels as $chan) {
                $status = $this->getChannelStatus($chan);
                $results[$chan] = $status;
                $totalCompletion += $status['completion'];
                $count++;
            }

            return [
                'completion' => $count > 0 ? round($totalCompletion / $count, 2) : 0,
                'channels' => $results
            ];
        }, self::DEFAULT_TTL);
    }

    /**
     * Get sync status for a specific channel
     *
     * @param string $channelName
     * @param string|null $targetAccountId
     * @return array
     */
    public function getChannelStatus(string $channelName, ?string $targetAccountId = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'channel:' . $channelName . ($targetAccountId ? ':' . $targetAccountId : '');

        return $this->cacheService->get($cacheKey, function () use ($channelName, $targetAccountId) {
            $conn = $this->entityManager->getConnection();
            
            // 1. Get Job Stats from DB
            $isPostgres = Helpers::isPostgres();
            $jsonExtract = $isPostgres 
                ? "payload->'params'->>'account_id'" 
                : "JSON_EXTRACT(payload, '$.params.account_id')";

            $query = "SELECT 
                        $jsonExtract as account_id,
                        status,
                        COUNT(*) as count
                      FROM jobs 
                      WHERE channel = :channel";
            
            $params = ['channel' => $channelName];
            
            if ($targetAccountId) {
                $query .= " AND $jsonExtract = :account_id";
                $params['account_id'] = $targetAccountId;
            }
            
            $query .= " GROUP BY account_id, status";
            
            $rows = $conn->fetchAllAssociative($query, $params);
            
            // 2. Process results
            $assets = [];
            foreach ($rows as $row) {
                $accId = trim((string)$row['account_id'], '"');
                if (!isset($assets[$accId])) {
                    $assets[$accId] = [
                        'total' => 0,
                        'completed' => 0,
                        'failed' => 0,
                        'processing' => 0,
                        'scheduled' => 0
                    ];
                }
                
                $status = (int)$row['status'];
                $count = (int)$row['count'];
                $assets[$accId]['total'] += $count;
                
                if ($status === JobStatus::completed->value) $assets[$accId]['completed'] += $count;
                elseif ($status === JobStatus::failed->value) $assets[$accId]['failed'] += $count;
                elseif ($status === JobStatus::processing->value) $assets[$accId]['processing'] += $count;
                elseif ($status === JobStatus::scheduled->value) $assets[$accId]['scheduled'] += $count;
            }
            
            // 3. Format assets and calculate percentages
            $formattedAssets = [];
            $totalChanCompletion = 0;
            
            foreach ($assets as $id => $stats) {
                $completion = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100, 2) : 0;
                $formattedAssets[] = array_merge(['id' => $id, 'completion' => $completion], $stats);
                $totalChanCompletion += $completion;
            }
            
            $assetCount = count($formattedAssets);
            
            return [
                'channel' => $channelName,
                'completion' => $assetCount > 0 ? round($totalChanCompletion / $assetCount, 2) : 0,
                'assets' => $formattedAssets
            ];
        }, self::DEFAULT_TTL);
    }

    /**
     * Invalidate sync telemetry cache
     *
     * @param string|null $channelName
     * @param string|null $accountId
     * @return void
     */
    public function invalidate(?string $channelName = null, ?string $accountId = null): void
    {
        if ($channelName && $accountId) {
            $this->cacheService->delete(self::CACHE_PREFIX . 'channel:' . $channelName . ':' . $accountId);
        }
        
        if ($channelName) {
            $this->cacheService->delete(self::CACHE_PREFIX . 'channel:' . $channelName);
        }
        
        $this->cacheService->delete(self::CACHE_PREFIX . 'global');
    }
}
