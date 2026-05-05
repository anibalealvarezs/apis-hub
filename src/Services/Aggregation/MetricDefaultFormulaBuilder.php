<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class MetricDefaultFormulaBuilder
{
    /**
     * @return array<string, string>
     */
    public function build(string $valCol, bool $isPostgres, string $periodCondition): array
    {
        $formulas = [
            'spend' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('spend', 'spend_daily')" : "mc.name IN ('spend', 'spend_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'clicks' => "SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
            'impressions' => "SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily', 'post_impressions', 'post_impressions_daily', 'page_impressions', 'page_impressions_daily', 'page_media_view', 'post_media_view', 'views', 'views_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
            'reach' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily')" : "mc.name IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'frequency' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('impressions', 'impressions_daily')" : "mc.name IN ('impressions', 'impressions_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('reach', 'reach_daily')" : "mc.name IN ('reach', 'reach_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END), 0)",
            'ctr' => "SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END), 0)",
            'cpc' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('spend', 'spend_daily')" : "mc.name IN ('spend', 'spend_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('clicks', 'clicks_daily')" : "mc.name IN ('clicks', 'clicks_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END), 0)",
            'cpm' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('spend', 'spend_daily')" : "mc.name IN ('spend', 'spend_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END) / (NULLIF(SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('impressions', 'impressions_daily')" : "mc.name IN ('impressions', 'impressions_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END), 0) / 1000)",
            'results' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'results'" : "mc.name = 'results'")." THEN $valCol ELSE 0 END)",
            'cost_per_result' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'spend'" : "mc.name = 'spend'")." THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'results'" : "mc.name = 'results'")." THEN $valCol ELSE 0 END), 0)",
            'result_rate' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'results'" : "mc.name = 'results'")." THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('impressions', 'page_media_view', 'post_media_view')" : "mc.name IN ('impressions', 'page_media_view', 'post_media_view')")." THEN $valCol ELSE 0 END), 0)",
            'roas' => "AVG(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'purchase_roas'" : "mc.name = 'purchase_roas'")." THEN $valCol ELSE NULL END)",
            'website_roas' => "AVG(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'website_purchase_roas'" : "mc.name = 'website_purchase_roas'")." THEN $valCol ELSE NULL END)",
            'actions' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'actions'" : "mc.name = 'actions'")." THEN $valCol ELSE 0 END)",
            'campaign_status' => 'MIN(rcc.status)',
            'purchase_roas' => "AVG(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'purchase_roas'" : "mc.name = 'purchase_roas'")." THEN $valCol ELSE NULL END)",
            'website_purchase_roas' => "AVG(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'website_purchase_roas'" : "mc.name = 'website_purchase_roas'")." THEN $valCol ELSE NULL END)",
            'total_interactions' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'page_post_engagements', 'page_post_engagements_daily')" : "mc.name IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'page_post_engagements', 'page_post_engagements_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'profile_views' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('profile_views', 'profile_views_daily')" : "mc.name IN ('profile_views', 'profile_views_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'follower_count' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('follower_count', 'follower_count_daily', 'page_fans', 'page_fans_daily')" : "mc.name IN ('follower_count', 'follower_count_daily', 'page_fans', 'page_fans_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'page_impressions' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('page_impressions', 'page_impressions_daily', 'page_media_view', 'post_media_view', 'views', 'views_daily')" : "mc.name IN ('page_impressions', 'page_impressions_daily', 'page_media_view', 'post_media_view', 'views', 'views_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'page_post_engagements' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('page_post_engagements', 'page_post_engagements_daily')" : "mc.name IN ('page_post_engagements', 'page_post_engagements_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'page_views_total' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('page_views_total', 'page_views_total_daily')" : "mc.name IN ('page_views_total', 'page_views_total_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'page_fans' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('page_fans', 'page_fans_daily')" : "mc.name IN ('page_fans', 'page_fans_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'post_impressions' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('post_impressions', 'post_impressions_daily', 'post_media_view', 'post_media_view_daily')" : "mc.name IN ('post_impressions', 'post_impressions_daily', 'post_media_view', 'post_media_view_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'post_engagement' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('post_engagement', 'post_engagement_daily')" : "mc.name IN ('post_engagement', 'post_engagement_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'likes' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily')" : "mc.name IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'comments' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily')" : "mc.name IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'shares' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily')" : "mc.name IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'saves' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('saves', 'saves_daily', 'saved', 'saved_daily')" : "mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'saved' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('saves', 'saves_daily', 'saved', 'saved_daily')" : "mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'plays' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily')" : "mc.name IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'views' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily')" : "mc.name IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily')")." AND ".($isPostgres ? "LOWER(mc.period) = 'daily'" : "mc.period = 'daily'")." THEN $valCol ELSE 0 END)",
            'engagement' => "SUM(CASE WHEN mc.name IN ('engagement', 'engagement_daily', 'post_engagement', 'post_engagement_daily', 'page_engagements', 'page_engagements_daily') THEN $valCol ELSE 0 END)",
            'video_views' => "SUM(CASE WHEN mc.name IN ('video_views', 'video_views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily') THEN $valCol ELSE 0 END)",
            'website_clicks' => "SUM(CASE WHEN mc.name IN ('website_clicks', 'website_clicks_daily') THEN $valCol ELSE 0 END)",
            'profile_links_taps' => "SUM(CASE WHEN mc.name IN ('profile_links_taps', 'profile_links_taps_daily') THEN $valCol ELSE 0 END)",
            'follows_and_unfollows' => "SUM(CASE WHEN mc.name IN ('follows_and_unfollows', 'follows_and_unfollows_daily') THEN $valCol ELSE 0 END)",
            'replies' => "SUM(CASE WHEN mc.name IN ('replies', 'replies_daily') THEN $valCol ELSE 0 END)",
            'accounts_engaged' => "SUM(CASE WHEN mc.name IN ('accounts_engaged', 'accounts_engaged_daily') THEN $valCol ELSE 0 END)",
            'follows' => "SUM(CASE WHEN mc.name IN ('follows', 'follows_daily') THEN $valCol ELSE 0 END)",
            'ig_reels_avg_watch_time' => "SUM(CASE WHEN mc.name IN ('ig_reels_avg_watch_time') THEN $valCol ELSE 0 END)",
            'ig_reels_video_view_total_time' => "SUM(CASE WHEN mc.name IN ('ig_reels_video_view_total_time') THEN $valCol ELSE 0 END)",
            'profile_activity' => "SUM(CASE WHEN mc.name IN ('profile_activity', 'profile_activity_daily') THEN $valCol ELSE 0 END)",
            'reposts' => "SUM(CASE WHEN mc.name IN ('reposts', 'reposts_daily') THEN $valCol ELSE 0 END)",
            'post_clicks' => "SUM(CASE WHEN mc.name IN ('post_clicks', 'post_clicks_daily') THEN $valCol ELSE 0 END)",
        ];

        $periodAwareOverrides = [
            'total_interactions' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'page_post_engagements', 'page_post_engagements_daily')" : "mc.name IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'page_post_engagements', 'page_post_engagements_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'comments' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily')" : "mc.name IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'likes' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily')" : "mc.name IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'shares' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily')" : "mc.name IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'saved' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('saves', 'saves_daily', 'saved', 'saved_daily')" : "mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'saves' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('saves', 'saves_daily', 'saved', 'saved_daily')" : "mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'reach' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily')" : "mc.name IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",
            'views' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily')" : "mc.name IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily')")." AND {$periodCondition} THEN $valCol ELSE 0 END)",

        ];

        return array_merge($formulas, $periodAwareOverrides);
    }
}

