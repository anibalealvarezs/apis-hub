<?php

    declare(strict_types=1);

    namespace Services\Sync;

    use DateInterval;
    use DatePeriod;
    use DateTime;
    use Doctrine\DBAL\Exception;
    use Enums\JobStatus;
    use Entities\Analytics\Channeled\ChanneledAccount;
    use Helpers\Helpers;
    use Services\CacheService;
    use Throwable;

    class SyncTelemetryService
    {
        private CacheService $cacheService;
        private const string CACHE_PREFIX = 'sync_telemetry:';
        private const int DEFAULT_TTL = 86400; // 24 hours

        public function __construct(CacheService $cacheService)
        {
            $this->cacheService = $cacheService;
        }

        /**
         * Get synchronization status for a channel or globally
         *
         * @param string|null $channelName
         * @param string|null $accountId
         * @return array
         * @throws Exception
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
         * @throws Exception
         */
        public function getGlobalStatus(): array
        {
            $cacheKey = self::CACHE_PREFIX.'global';

            return $this->cacheService->get($cacheKey, function () {
                $em = Helpers::getManager();
                $conn = $em->getConnection();
                $isPostgres = Helpers::isPostgres($em);

                $jsonExtract = $isPostgres
                    ? "COALESCE(payload->>'account_id', payload->'params'->>'account_id', 'global')"
                    : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.account_id')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.account_id')), 'global')";

                $isRecentJob = $isPostgres
                    ? "(payload->'params'->>'type' = 'recent')"
                    : "(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.type')) = 'recent')";

                $sql = "
                    SELECT
                        channel,
                        $jsonExtract as account_id,
                        COUNT(*) as total,
                        SUM(CASE WHEN status = :completed THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status = :failed THEN 1 ELSE 0 END) as failed,
                        SUM(CASE WHEN status = :processing THEN 1 ELSE 0 END) as processing,
                        SUM(CASE WHEN status = :scheduled THEN 1 ELSE 0 END) as scheduled,
                        SUM(CASE WHEN $isRecentJob AND status IN (:scheduled, :delayed, :cancelled) THEN 0 ELSE 1 END) as total_for_percentage
                    FROM jobs
                    GROUP BY channel, account_id
                ";

                $rows = $conn->fetchAllAssociative($sql, [
                    'completed' => JobStatus::completed->value,
                    'failed' => JobStatus::failed->value,
                    'processing' => JobStatus::processing->value,
                    'scheduled' => JobStatus::scheduled->value,
                    'delayed' => JobStatus::delayed->value,
                    'cancelled' => JobStatus::cancelled->value,
                ]);

                // 2. Group by channel and process
                $chanStats = [];
                foreach ($rows as $row) {
                    $chan = $row['channel'];
                    if (!isset($chanStats[$chan])) {
                        $chanStats[$chan] = ['assets' => []];
                    }

                    $accId = ltrim(trim((string)$row['account_id'], '"'), '#');
                    if ($accId === 'null' || ($accId === '' && $row['account_id'] !== 'global')) continue;

                    $chanStats[$chan]['assets'][$accId] = [
                        'total'      => (int)$row['total'],
                        'total_for_percentage' => (int)$row['total_for_percentage'],
                        'completed'  => (int)$row['completed'],
                        'failed'     => (int)$row['failed'],
                        'processing' => (int)$row['processing'],
                        'scheduled'  => (int)$row['scheduled']
                    ];
                }

                // 3. Final formatting
                $results = [];
                $globalTotalCompletion = 0;
                $globalTotalAssets = 0;
                $globalFullySynced = 0;
                $totalChans = 0;

                foreach ($chanStats as $chan => $data) {
                    $chanTotalCompletion = 0;
                    $chanFullySynced = 0;
                    $chanTotalJobs = 0;
                    $chanCompleted = 0;
                    $chanProcessing = 0;
                    $chanFailed = 0;
                    $chanScheduled = 0;

                    $assetCount = count($data['assets']);
                    foreach ($data['assets'] as $asset) {
                        $completion = $asset['total_for_percentage'] > 0 ? round(($asset['completed'] / $asset['total_for_percentage']) * 100, 2) : 0;
                        if ($completion >= 100) $chanFullySynced++;
                        $chanTotalCompletion += $completion;

                        $chanTotalJobs += $asset['total'];
                        $chanCompleted += $asset['completed'];
                        $chanProcessing += $asset['processing'];
                        $chanFailed += $asset['failed'];
                        $chanScheduled += $asset['scheduled'];
                    }

                    $chanCompletion = $assetCount > 0 ? round($chanTotalCompletion / $assetCount, 2) : 0;

                    $results[$chan] = [
                        'channel'                 => $chan,
                        'assets'                  => $data['assets'], // CRITICAL: Added back for detailed view
                        'completion_percentage'   => $chanCompletion,
                        'fully_synced_count'      => $chanFullySynced,
                        'fully_synced_percentage' => $assetCount > 0 ? round(($chanFullySynced / $assetCount) * 100, 2) : 0,
                        'total_assets'            => $assetCount,
                        'total_jobs'              => $chanTotalJobs,
                        'completed'               => $chanCompleted,
                        'processing'              => $chanProcessing,
                        'failed'                  => $chanFailed,
                        'scheduled'               => $chanScheduled
                    ];

                    $globalTotalCompletion += $chanCompletion;
                    $globalTotalAssets += $assetCount;
                    $globalFullySynced += $chanFullySynced;
                    $totalChans++;
                }

                return [
                    'completion_percentage'   => $totalChans > 0 ? round($globalTotalCompletion / $totalChans, 2) : 0,
                    'total_assets'            => $globalTotalAssets,
                    'fully_synced_count'      => $globalFullySynced,
                    'fully_synced_percentage' => $globalTotalAssets > 0 ? round(($globalFullySynced / $globalTotalAssets) * 100, 2) : 0,
                    'channels'                => $results
                ];
            });
        }

        /**
         * Get synchronization status for a specific channel
         *
         * @param string $channelName
         * @param string|null $targetAccountId
         * @return array
         * @throws Exception
         */
        public function getChannelStatus(string $channelName, ?string $targetAccountId = null): array
        {
            $cacheKey = self::CACHE_PREFIX.'channel:'.$channelName.($targetAccountId ? ':'.$targetAccountId : '');

            return $this->cacheService->get($cacheKey, function () use ($channelName, $targetAccountId) {
                $em = Helpers::getManager();
                $conn = $em->getConnection();
                $isPostgres = Helpers::isPostgres($em);

                $jsonExtract = $isPostgres
                    ? "COALESCE(payload->>'account_id', payload->'params'->>'account_id', 'global')"
                    : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.account_id')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.account_id')), 'global')";

                $isRecentJob = $isPostgres
                    ? "(payload->'params'->>'type' = 'recent' OR payload->'params'->>'startDate' = '-3 days' OR payload->'params'->>'start_date' = '-3 days')"
                    : "(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.type')) = 'recent' OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.startDate')) = '-3 days' OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.start_date')) = '-3 days')";

                $query = "
                    SELECT
                        $jsonExtract as account_id,
                        COUNT(*) as total,
                        SUM(CASE WHEN status = :completed THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status = :failed THEN 1 ELSE 0 END) as failed,
                        SUM(CASE WHEN status = :processing THEN 1 ELSE 0 END) as processing,
                        SUM(CASE WHEN status = :scheduled THEN 1 ELSE 0 END) as scheduled,
                        SUM(CASE WHEN NOT $isRecentJob AND status = :completed THEN 1 ELSE 0 END) as completed_for_percentage,
                        SUM(CASE WHEN NOT $isRecentJob THEN 1 ELSE 0 END) as total_for_percentage
                    FROM jobs
                    WHERE channel = :channel
                ";

                $params = [
                    'channel' => $channelName,
                    'completed' => JobStatus::completed->value,
                    'failed' => JobStatus::failed->value,
                    'processing' => JobStatus::processing->value,
                    'scheduled' => JobStatus::scheduled->value,
                ];

                if ($targetAccountId) {
                    $query .= " AND $jsonExtract = :account_id";
                    $params['account_id'] = $targetAccountId;
                }

                $query .= " GROUP BY account_id";

                $rows = $conn->fetchAllAssociative($query, $params);

                // 2. Process results
                $assets = [];
                foreach ($rows as $row) {
                    $accId = ltrim(trim((string)$row['account_id'], '"'), '#');
                    if ($accId === 'null' || ($accId === '' && $row['account_id'] !== 'global')) continue;

                    $assets[$accId] = [
                        'total'      => (int)$row['total'],
                        'total_for_percentage' => (int)$row['total_for_percentage'],
                        'completed_for_percentage' => (int)($row['completed_for_percentage'] ?? 0),
                        'completed'  => (int)$row['completed'],
                        'failed'     => (int)$row['failed'],
                        'processing' => (int)$row['processing'],
                        'scheduled'  => (int)$row['scheduled']
                    ];
                }

                // 3. Format assets and calculate totals
                $formattedAssets = [];
                $platformIds = [];
                $totalChanCompletion = 0;
                $chanTotal = 0;
                $chanCompleted = 0;
                $chanProcessing = 0;
                $chanFailed = 0;
                $chanScheduled = 0;
                $fullySyncedCount = 0;

                foreach ($assets as $id => $stats) {
                    $completion = $stats['total_for_percentage'] > 0 ? round(($stats['completed_for_percentage'] / $stats['total_for_percentage']) * 100, 2) : 0;
                    if ($completion > 100) $completion = 100;
                    if ($completion >= 100) $fullySyncedCount++;

                    $assetObj = array_merge(['id' => $id, 'completion' => $completion, 'enabled' => true], $stats);
                    $formattedAssets[$id] = $assetObj;
                    $platformIds[] = $id;

                    $totalChanCompletion += $completion;
                    $chanTotal += $stats['total'];
                    $chanCompleted += $stats['completed'];
                    $chanProcessing += $stats['processing'];
                    $chanFailed += $stats['failed'];
                    $chanScheduled += $stats['scheduled'];
                }

                // 4. Enrich with names from ChanneledAccount
                if (!empty($platformIds)) {
                    try {
                        $caRepo = $em->getRepository(ChanneledAccount::class);
                        $channeledAccounts = $caRepo->findBy([
                            'channel'    => $channelName,
                            'platformId' => $platformIds
                        ]);
                        foreach ($channeledAccounts as $ca) {
                            $pId = $ca->getPlatformId();
                            if (isset($formattedAssets[$pId])) {
                                $formattedAssets[$pId]['name'] = $ca->getName();
                                $formattedAssets[$pId]['enabled'] = $ca->isEnabled();
                            }
                        }
                    } catch (Throwable $e) {
                        // Silently fail name enrichment to avoid breaking telemetry
                    }
                }

                $finalAssets = array_values($formattedAssets);
                $assetCount = count($finalAssets);

                return [
                    'channel'                 => $channelName,
                    'completion_percentage'   => $assetCount > 0 ? round($totalChanCompletion / $assetCount, 2) : 0,
                    'fully_synced_count'      => $fullySyncedCount,
                    'fully_synced_percentage' => $assetCount > 0 ? round(($fullySyncedCount / $assetCount) * 100, 2) : 0,
                    'total_assets'            => $assetCount,
                    'total_jobs'              => $chanTotal,
                    'completed'               => $chanCompleted,
                    'processing'              => $chanProcessing,
                    'failed'                  => $chanFailed,
                    'scheduled'               => $chanScheduled,
                    'assets'                  => $finalAssets
                ];
            }, self::DEFAULT_TTL);
        }

        /**
         * Get daily sync map for a specific account (GitHub-style chart data)
         *
         * @param string $channel
         * @param string $accountId
         * @return array
         * @throws Exception
         */
        public function getAccountDailyStats(string $channel, string $accountId): array
        {
            $cacheKey = self::CACHE_PREFIX.'daily:'.$channel.':'.$accountId;

            return $this->cacheService->get($cacheKey, function () use ($channel, $accountId) {
                $em = Helpers::getManager();
                $conn = $em->getConnection();
                $isPostgres = Helpers::isPostgres($em);

                $jsonExtract = $isPostgres
                    ? "COALESCE(payload->>'account_id', payload->'params'->>'account_id', 'global')"
                    : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.account_id')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.account_id')), 'global')";

                $jsonStart = $isPostgres ? "payload->'params'->>'startDate'" : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.startDate'))";
                $jsonEnd = $isPostgres ? "payload->'params'->>'endDate'" : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.params.endDate'))";

                $query = "SELECT $jsonStart as start_date, $jsonEnd as end_date
                      FROM jobs 
                      WHERE channel = :channel 
                      AND $jsonExtract = :account_id
                      AND status = :status";

                $rows = $conn->fetchAllAssociative($query, [
                    'channel'    => $channel,
                    'account_id' => $accountId,
                    'status'     => JobStatus::completed->value
                ]);

                $dailyMap = [];
                foreach ($rows as $row) {
                    $start = $row['start_date'] ? trim($row['start_date'], '"') : null;
                    $end = $row['end_date'] ? trim($row['end_date'], '"') : null;

                    if (!$start) continue;
                    if (!$end) $end = $start;

                    try {
                        $period = new DatePeriod(
                            new DateTime($start),
                            new DateInterval('P1D'),
                            (new DateTime($end))->modify('+1 day')
                        );
                        foreach ($period as $date) {
                            $dailyMap[$date->format('Y-m-d')] = true;
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                }

                $name = $accountId;
                try {
                    $caRepo = $em->getRepository(ChanneledAccount::class);
                    $ca = $caRepo->findOneBy([
                        'channel'    => $channel,
                        'platformId' => $accountId
                    ]);
                    if ($ca) {
                        $name = $ca->getName();
                    }
                } catch (Throwable $e) {
                    // Silently fail name enrichment
                }

                return [
                    'account_id'     => $accountId,
                    'name'           => $name,
                    'channel'        => $channel,
                    'completed_days' => array_keys($dailyMap)
                ];
            }); // 1 hour cache
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
                $this->cacheService->delete(self::CACHE_PREFIX.'channel:'.$channelName.':'.$accountId);
            }

            if ($channelName) {
                $this->cacheService->delete(self::CACHE_PREFIX.'channel:'.$channelName);
            }

            $this->cacheService->delete(self::CACHE_PREFIX.'global');
        }
    }