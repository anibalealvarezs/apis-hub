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
            $totalAssets = 0;
            $totalFullySynced = 0;
            $count = 0;

            foreach ($enabledChannels as $chan) {
                $status = $this->getChannelStatus($chan);
                $results[$chan] = $status;
                $totalCompletion += $status['completion_percentage'];
                $totalAssets += ($status['total_assets'] ?? 0);
                $totalFullySynced += ($status['fully_synced_count'] ?? 0);
                $count++;
            }

            return [
                'completion_percentage' => $count > 0 ? round($totalCompletion / $count, 2) : 0,
                'total_assets' => $totalAssets,
                'fully_synced_count' => $totalFullySynced,
                'fully_synced_percentage' => $totalAssets > 0 ? round(($totalFullySynced / $totalAssets) * 100, 2) : 0,
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
                if ($accId === 'null' || $accId === '') continue;

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
            
            // 3. Format assets and calculate totals
            $formattedAssets = [];
            $totalChanCompletion = 0;
            $chanTotal = 0;
            $chanCompleted = 0;
            $chanProcessing = 0;
            $chanFailed = 0;
            $chanScheduled = 0;
            $fullySyncedCount = 0;
            
            foreach ($assets as $id => $stats) {
                $completion = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100, 2) : 0;
                if ($completion >= 100) $fullySyncedCount++;

                $formattedAssets[] = array_merge(['id' => $id, 'completion' => $completion], $stats);
                $totalChanCompletion += $completion;
                
                $chanTotal += $stats['total'];
                $chanCompleted += $stats['completed'];
                $chanProcessing += $stats['processing'];
                $chanFailed += $stats['failed'];
                $chanScheduled += $stats['scheduled'];
            }
            
            $assetCount = count($formattedAssets);
            
            return [
                'channel' => $channelName,
                'completion_percentage' => $assetCount > 0 ? round($totalChanCompletion / $assetCount, 2) : 0,
                'fully_synced_count' => $fullySyncedCount,
                'fully_synced_percentage' => $assetCount > 0 ? round(($fullySyncedCount / $assetCount) * 100, 2) : 0,
                'total_assets' => $assetCount,
                'total_jobs' => $chanTotal,
                'completed' => $chanCompleted,
                'processing' => $chanProcessing,
                'failed' => $chanFailed,
                'scheduled' => $chanScheduled,
                'assets' => $formattedAssets
            ];
        }, self::DEFAULT_TTL);
    }

    /**
     * Get daily sync map for a specific account (GitHub-style chart data)
     *
     * @param string $channel
     * @param string $accountId
     * @return array
     */
    public function getAccountDailyStats(string $channel, string $accountId): array
    {
        $cacheKey = self::CACHE_PREFIX . 'daily:' . $channel . ':' . $accountId;
        
        return $this->cacheService->get($cacheKey, function () use ($channel, $accountId) {
            $conn = $this->entityManager->getConnection();
            $isPostgres = Helpers::isPostgres();
            
            $jsonExtract = $isPostgres 
                ? "payload->'params'->>'account_id'" 
                : "JSON_EXTRACT(payload, '$.params.account_id')";
            
            $jsonStart = $isPostgres ? "payload->'params'->>'startDate'" : "JSON_EXTRACT(payload, '$.params.startDate')";
            $jsonEnd = $isPostgres ? "payload->'params'->>'endDate'" : "JSON_EXTRACT(payload, '$.params.endDate')";

            $query = "SELECT $jsonStart as start_date, $jsonEnd as end_date
                      FROM jobs 
                      WHERE channel = :channel 
                      AND $jsonExtract = :account_id
                      AND status = :status";
            
            $rows = $conn->fetchAllAssociative($query, [
                'channel' => $channel,
                'account_id' => $accountId,
                'status' => JobStatus::completed->value
            ]);

            $dailyMap = [];
            foreach ($rows as $row) {
                $start = $row['start_date'] ? trim($row['start_date'], '"') : null;
                $end = $row['end_date'] ? trim($row['end_date'], '"') : null;
                
                if (!$start) continue;
                if (!$end) $end = $start;

                try {
                    $period = new \DatePeriod(
                        new \DateTime($start),
                        new \DateInterval('P1D'),
                        (new \DateTime($end))->modify('+1 day')
                    );
                    foreach ($period as $date) {
                        $dailyMap[$date->format('Y-m-d')] = true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            return [
                'account_id' => $accountId,
                'channel' => $channel,
                'completed_days' => array_keys($dailyMap)
            ];
        }, 3600); // 1 hour cache
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
