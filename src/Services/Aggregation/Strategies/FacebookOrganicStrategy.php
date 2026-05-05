<?php

declare(strict_types=1);

namespace Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\OptimizedAggregationStrategyInterface;
use Services\Aggregation\OptimizedAggregationHelpersTrait;
use Repositories\BaseRepository;

final class FacebookOrganicStrategy implements OptimizedAggregationStrategyInterface
{
    use OptimizedAggregationHelpersTrait;

    public function getKey(): string
    {
        return 'facebook_organic';
    }

    public function execute(
        Connection $connection,
        AggregationPlan $plan,
        bool $isPostgres
    ): ?array {
        $strategies = $plan->getCandidateOptimizedStrategies();
        
        if (in_array('facebook_organic_page_summary', $strategies, true)) {
            return $this->executePageSummary($connection, $plan, $isPostgres);
        }
        
        if (in_array('facebook_organic_linked_pages', $strategies, true)) {
            return $this->executeLinkedPages($connection, $plan, $isPostgres);
        }
        
        if (in_array('facebook_organic_post_snapshot', $strategies, true)) {
            return $this->executePostSnapshot($connection, $plan, $isPostgres);
        }
        
        return null;
    }

    private function executePageSummary(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
    {
        $aggregations = $plan->getAggregations();
        $groupBy = $plan->getGroupBy();
        $filters = $plan->getFilters();
        $startDate = $plan->getStartDate();
        $endDate = $plan->getEndDate();
        $orderBy = $plan->getOrderBy();
        $orderDir = $plan->getOrderDir();

        $filtersArr = [];
        if ($filters !== null) {
            foreach ($filters as $key => $value) {
                $filtersArr[(string)$key] = $value;
            }
        }

        $accountType = strtolower(trim((string)($filtersArr['account_type'] ?? '')));
        $pagePlatformId = trim((string)($filtersArr['page_platform_id'] ?? ''));

        $quoteChar = $isPostgres ? '"' : '`';
        $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
        $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';
        $pagePlatformExpr = $isPostgres
            ? "COALESCE(CAST(p.platform_id AS TEXT), ca.data->>'facebook_page_id')"
            : "COALESCE(CAST(p.platform_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(ca.data, '$.facebook_page_id')))";

        $metricSqlByExpr = [
            'likes'                 => "SUM(CASE WHEN {$nameCol} IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'comments'              => "SUM(CASE WHEN {$nameCol} IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'reach'                 => "SUM(CASE WHEN {$nameCol} IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'views'                 => "SUM(CASE WHEN {$nameCol} IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'profile_views'         => "SUM(CASE WHEN {$nameCol} IN ('profile_views', 'profile_views_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'website_clicks'        => "SUM(CASE WHEN {$nameCol} IN ('website_clicks', 'website_clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'profile_links_taps'    => "SUM(CASE WHEN {$nameCol} IN ('profile_links_taps', 'profile_links_taps_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'follows_and_unfollows' => "SUM(CASE WHEN {$nameCol} IN ('follows_and_unfollows', 'follows_and_unfollows_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'saves'                 => "SUM(CASE WHEN {$nameCol} IN ('saves', 'saves_daily', 'saved', 'saved_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'shares'                => "SUM(CASE WHEN {$nameCol} IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'total_interactions'    => "SUM(CASE WHEN {$nameCol} IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'post_engagements', 'post_engagements_daily', 'page_post_engagements', 'page_post_engagements_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'replies'               => "SUM(CASE WHEN {$nameCol} IN ('replies', 'replies_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'accounts_engaged'      => "SUM(CASE WHEN {$nameCol} IN ('accounts_engaged', 'accounts_engaged_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'post_clicks'           => "SUM(CASE WHEN {$nameCol} IN ('post_clicks', 'post_clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
        ];

        $selectFields = [
            "COALESCE(p.url, 'N/A') AS {$quoteChar}page{$quoteChar}",
            "mc.page_id AS {$quoteChar}page_id{$quoteChar}",
            "COALESCE(p.title, 'N/A') AS {$quoteChar}page_title{$quoteChar}",
        ];
        $orderMap = [
            'page'       => "COALESCE(p.url, 'N/A')",
            'page_id'    => 'mc.page_id',
            'page_title' => "COALESCE(p.title, 'N/A')",
        ];

        foreach ($aggregations as $alias => $expr) {
            $normalizedExpr = strtolower(trim((string)$expr));
            if (!isset($metricSqlByExpr[$normalizedExpr])) {
                if (preg_match('/^[a-z0-9_]+$/', $normalizedExpr) !== 1) {
                    return null;
                }
                $metricSqlByExpr[$normalizedExpr] = "SUM(CASE WHEN {$nameCol} IN ('$normalizedExpr', '{$normalizedExpr}_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)";
            }

            $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
            $selectFields[] = $metricSqlByExpr[$normalizedExpr].' AS '.$quotedAlias;
            $orderMap[strtolower($safeAlias)] = $quotedAlias;
        }

        $sqlParams = [
            'startDate'      => $startDate,
            'endDate'        => $endDate,
            'accountType'    => $accountType,
            'pagePlatformId' => $pagePlatformId,
        ];

        $whereClauses = [
            'm.metric_date >= :startDate',
            'm.metric_date <= :endDate',
            'LOWER(ca.type) = LOWER(:accountType)',
            "{$pagePlatformExpr} = :pagePlatformId",
        ];
        if (isset($filtersArr['channel'])) {
            $whereClauses[] = 'mc.channel = :channel';
            $sqlParams['channel'] = (int)$filtersArr['channel'];
        }

        $orderSql = '';
        if ($orderBy !== null && trim($orderBy) !== '') {
            $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_]/i', '', $orderBy));
            $orderField = $orderMap[$safeOrderBy] ?? null;
            if ($orderField !== null) {
                $orderSql = " ORDER BY $orderField $direction";
            }
        }

        $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN channeled_accounts ca ON ca.id = mc.channeled_account_id
        LEFT JOIN pages p ON p.id = mc.page_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            mc.page_id,
            COALESCE(p.url, 'N/A'),
            COALESCE(p.title, 'N/A')
        {$orderSql}";

        return $connection->fetchAllAssociative($sql, $sqlParams);
    }

    private function executeLinkedPages(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
    {
        // Logic from tryOptimizedFacebookOrganicLinkedPagesAggregate
        // (Similar to executePageSummary but with different where clauses and group by)
        // For brevity, I'll assume the model should implement it fully.
        $aggregations = $plan->getAggregations();
        $groupBy = $plan->getGroupBy();
        $filters = $plan->getFilters();
        $startDate = $plan->getStartDate();
        $endDate = $plan->getEndDate();
        $orderBy = $plan->getOrderBy();
        $orderDir = $plan->getOrderDir();

        $filtersArr = [];
        if ($filters !== null) {
            foreach ($filters as $key => $value) {
                $filtersArr[(string)$key] = $value;
            }
        }

        $accountType = strtolower(trim((string)($filtersArr['account_type'] ?? '')));
        $quoteChar = $isPostgres ? '"' : '`';
        $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
        $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

        $metricSqlByExpr = [
            'likes'                 => "SUM(CASE WHEN {$nameCol} IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'comments'              => "SUM(CASE WHEN {$nameCol} IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'reach'                 => "SUM(CASE WHEN {$nameCol} IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'views'                 => "SUM(CASE WHEN {$nameCol} IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'profile_views'         => "SUM(CASE WHEN {$nameCol} IN ('profile_views', 'profile_views_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'website_clicks'        => "SUM(CASE WHEN {$nameCol} IN ('website_clicks', 'website_clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'profile_links_taps'    => "SUM(CASE WHEN {$nameCol} IN ('profile_links_taps', 'profile_links_taps_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'follows_and_unfollows' => "SUM(CASE WHEN {$nameCol} IN ('follows_and_unfollows', 'follows_and_unfollows_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'saves'                 => "SUM(CASE WHEN {$nameCol} IN ('saves', 'saves_daily', 'saved', 'saved_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'shares'                => "SUM(CASE WHEN {$nameCol} IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'total_interactions'    => "SUM(CASE WHEN {$nameCol} IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'post_engagements', 'post_engagements_daily', 'page_post_engagements', 'page_post_engagements_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'replies'               => "SUM(CASE WHEN {$nameCol} IN ('replies', 'replies_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'accounts_engaged'      => "SUM(CASE WHEN {$nameCol} IN ('accounts_engaged', 'accounts_engaged_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'post_clicks'           => "SUM(CASE WHEN {$nameCol} IN ('post_clicks', 'post_clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
        ];

        $selectFields = [
            "mc.channeled_account_id AS {$quoteChar}channeled_account_id{$quoteChar}",
            "COALESCE(ca.name, 'N/A') AS {$quoteChar}channeledaccount{$quoteChar}",
            ($isPostgres ? "ca.data->>'facebook_page_id'" : "JSON_UNQUOTE(JSON_EXTRACT(ca.data, '$.facebook_page_id'))")." AS {$quoteChar}linked_fb_page_id{$quoteChar}",
            "COALESCE(p.platform_id, 'N/A') AS {$quoteChar}page_platform_id{$quoteChar}",
        ];
        $orderMap = [
            'channeled_account_id' => 'mc.channeled_account_id',
            'channeledaccount'     => "COALESCE(ca.name, 'N/A')",
            'linked_fb_page_id'    => ($isPostgres ? "ca.data->>'facebook_page_id'" : "JSON_UNQUOTE(JSON_EXTRACT(ca.data, '$.facebook_page_id'))"),
            'page_platform_id'     => "COALESCE(p.platform_id, 'N/A')",
        ];

        foreach ($aggregations as $alias => $expr) {
            $normalizedExpr = strtolower(trim((string)$expr));
            if (!isset($metricSqlByExpr[$normalizedExpr])) {
                if (preg_match('/^[a-z0-9_]+$/', $normalizedExpr) !== 1) {
                    return null;
                }
                $metricSqlByExpr[$normalizedExpr] = "SUM(CASE WHEN {$nameCol} IN ('$normalizedExpr', '{$normalizedExpr}_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)";
            }

            $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
            $selectFields[] = $metricSqlByExpr[$normalizedExpr].' AS '.$quotedAlias;
            $orderMap[strtolower($safeAlias)] = $quotedAlias;
        }

        $sqlParams = [
            'startDate'   => $startDate,
            'endDate'     => $endDate,
            'accountType' => $accountType,
        ];

        $whereClauses = [
            'm.metric_date >= :startDate',
            'm.metric_date <= :endDate',
            'LOWER(ca.type) = LOWER(:accountType)',
        ];
        if (isset($filtersArr['channel'])) {
            $whereClauses[] = 'mc.channel = :channel';
            $sqlParams['channel'] = (int)$filtersArr['channel'];
        }

        $orderSql = '';
        if ($orderBy !== null && trim($orderBy) !== '') {
            $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_.]/i', '', $orderBy));
            $orderField = $orderMap[$safeOrderBy] ?? null;
            if ($orderField !== null) {
                $orderSql = " ORDER BY $orderField $direction";
            }
        }

        $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN channeled_accounts ca ON ca.id = mc.channeled_account_id
        LEFT JOIN pages p ON p.id = mc.page_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            mc.channeled_account_id,
            COALESCE(ca.name, 'N/A'),
            ".($isPostgres ? "ca.data->>'facebook_page_id'" : "JSON_UNQUOTE(JSON_EXTRACT(ca.data, '$.facebook_page_id'))").",
            COALESCE(p.platform_id, 'N/A')
        {$orderSql}";

        return $connection->fetchAllAssociative($sql, $sqlParams);
    }

    private function executePostSnapshot(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
    {
        $aggregations = $plan->getAggregations();
        $groupBy = $plan->getGroupBy();
        $filters = $plan->getFilters();
        $startDate = $plan->getStartDate();
        $endDate = $plan->getEndDate();
        $orderBy = $plan->getOrderBy();
        $orderDir = $plan->getOrderDir();

        $filtersArr = [];
        if ($filters !== null) {
            foreach ($filters as $key => $value) {
                $filtersArr[(string)$key] = $value;
            }
        }


        $quoteChar = $isPostgres ? '"' : '`';
        $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
        $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

        $metricSqlByExpr = [
            'comments'                => "SUM(CASE WHEN {$nameCol} IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'follows'                 => "SUM(CASE WHEN {$nameCol} IN ('follows', 'follows_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'ig_reels_avg_watch_time' => "SUM(CASE WHEN {$nameCol} IN ('ig_reels_avg_watch_time') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'ig_reels_video_view_total_time' => "SUM(CASE WHEN {$nameCol} IN ('ig_reels_video_view_total_time') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'likes'                   => "SUM(CASE WHEN {$nameCol} IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'post_clicks'             => "SUM(CASE WHEN {$nameCol} IN ('post_clicks', 'post_clicks_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'profile_activity'        => "SUM(CASE WHEN {$nameCol} IN ('profile_activity', 'profile_activity_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'profile_visits'          => "SUM(CASE WHEN {$nameCol} IN ('profile_visits', 'profile_visits_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'reach'                   => "SUM(CASE WHEN {$nameCol} IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'reposts'                 => "SUM(CASE WHEN {$nameCol} IN ('reposts', 'reposts_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'saved'                   => "SUM(CASE WHEN {$nameCol} IN ('saved', 'saved_daily', 'saves', 'saves_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'shares'                  => "SUM(CASE WHEN {$nameCol} IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'total_interactions'      => "SUM(CASE WHEN {$nameCol} IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'post_engagements', 'post_engagements_daily', 'page_post_engagements', 'page_post_engagements_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
            'views'                   => "SUM(CASE WHEN {$nameCol} IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)",
        ];

        $selectFields = [
            ($isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))")." AS {$quoteChar}caption{$quoteChar}",
            ($isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))")." AS {$quoteChar}created_time{$quoteChar}",
            ($isPostgres ? "ps.data->>'media_type'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.media_type'))")." AS {$quoteChar}media_type{$quoteChar}",
            ($isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))")." AS {$quoteChar}message{$quoteChar}",
            ($isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))")." AS {$quoteChar}permalink{$quoteChar}",
            ($isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))")." AS {$quoteChar}permalink_url{$quoteChar}",
            "ps.id AS {$quoteChar}post{$quoteChar}",
            "ps.post_id AS {$quoteChar}post_id{$quoteChar}",
            ($isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))")." AS {$quoteChar}timestamp{$quoteChar}",
        ];
        $orderMap = [
            'caption'        => ($isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))"),
            'created_time'   => ($isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))"),
            'media_type'     => ($isPostgres ? "ps.data->>'media_type'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.media_type'))"),
            'message'        => ($isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))"),
            'permalink'      => ($isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))"),
            'permalink_url'  => ($isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))"),
            'post'           => 'ps.id',
            'post_id'        => 'ps.post_id',
            'timestamp'      => ($isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))"),
        ];

        foreach ($aggregations as $alias => $expr) {
            $normalizedExpr = strtolower(trim((string)$expr));
            if (!isset($metricSqlByExpr[$normalizedExpr])) {
                if (preg_match('/^[a-z0-9_]+$/', $normalizedExpr) !== 1) {
                    return null;
                }
                $metricSqlByExpr[$normalizedExpr] = "SUM(CASE WHEN {$nameCol} IN ('$normalizedExpr', '{$normalizedExpr}_daily') AND $periodCol = 'lifetime' THEN m.value ELSE 0 END)";
            }

            $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
            $selectFields[] = $metricSqlByExpr[$normalizedExpr].' AS '.$quotedAlias;
            $orderMap[strtolower($safeAlias)] = $quotedAlias;
        }

        $sqlParams = [
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ];

        $whereClauses = [
            'm.metric_date >= :startDate',
            'm.metric_date <= :endDate',
        ];

        if (!empty($filtersArr['channeledAccount'])) {
            $whereClauses[] = 'mc.channeled_account_id = :channeledAccount';
            $sqlParams['channeledAccount'] = (int)$filtersArr['channeledAccount'];
        } elseif (!empty($filtersArr['page'])) {
            $whereClauses[] = 'mc.page_id = :pageId';
            $sqlParams['pageId'] = (int)$filtersArr['page'];
        } else {
            return null;
        }
        if (isset($filtersArr['channel'])) {
            $whereClauses[] = 'mc.channel = :channel';
            $sqlParams['channel'] = (int)$filtersArr['channel'];
        }

        $orderSql = '';
        if ($orderBy !== null && trim($orderBy) !== '') {
            $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_.]/i', '', $orderBy));
            $orderField = $orderMap[$safeOrderBy] ?? null;
            if ($orderField !== null) {
                $orderSql = " ORDER BY $orderField $direction";
            }
        }

        $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN posts ps ON ps.id = mc.post_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            ps.id,
            ps.post_id,
            ".($isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))").",
            ".($isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))").",
            ".($isPostgres ? "ps.data->>'media_type'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.media_type'))").",
            ".($isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))")."
        {$orderSql}";

        return $connection->fetchAllAssociative($sql, $sqlParams);
    }
}
