<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Anibalealvarezs\ApiDriverCore\Classes\CanonicalMetricDefinitionRegistry;

    final class MetricDefaultFormulaBuilder
    {
        /**
         * @return array<string, string>
         */
        public function build(string $valCol, bool $isPostgres, string $periodCondition): array
        {
            $getNameExpr = function (string $canonical, bool $includeDaily = true) use ($isPostgres): string {
                $names = CanonicalMetricDefinitionRegistry::getAllAssociatedNames($canonical);
                if ($includeDaily) {
                    foreach ($names as $n) {
                        if (!str_ends_with($n, '_daily')) {
                            $names[] = $n.'_daily';
                        }
                    }
                }
                $names = array_values(array_unique($names));
                $list = "'".implode("', '", $names)."'";

                return ($isPostgres ? "LOWER(mc.name)" : "mc.name")." IN ($list)";
            };

            return [
                'spend'                          => "SUM(CASE WHEN {$getNameExpr('spend')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'clicks'                         => "SUM(CASE WHEN {$getNameExpr('clicks')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'impressions'                    => "SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'reach'                          => "SUM(CASE WHEN {$getNameExpr('reach')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'frequency'                      => "SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN {$getNameExpr('reach')} AND $periodCondition THEN $valCol ELSE 0 END), 0)",
                'ctr'                            => "SUM(CASE WHEN {$getNameExpr('clicks')} AND $periodCondition THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END), 0)",
                'cpc'                            => "SUM(CASE WHEN {$getNameExpr('spend')} AND $periodCondition THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN {$getNameExpr('clicks')} AND $periodCondition THEN $valCol ELSE 0 END), 0)",
                'cpm'                            => "SUM(CASE WHEN {$getNameExpr('spend')} AND $periodCondition THEN $valCol ELSE 0 END) / (NULLIF(SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END), 0) / 1000)",
                'results'                        => "SUM(CASE WHEN {$getNameExpr('conversions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'cost_per_result'                => "SUM(CASE WHEN {$getNameExpr('spend')} AND $periodCondition THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN {$getNameExpr('conversions')} AND $periodCondition THEN $valCol ELSE 0 END), 0)",
                'result_rate'                    => "SUM(CASE WHEN {$getNameExpr('conversions')} AND $periodCondition THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END), 0)",
                'roas'                           => "AVG(CASE WHEN {$getNameExpr('roas_purchase')} AND $periodCondition THEN $valCol ELSE NULL END)",
                'website_roas'                   => "AVG(CASE WHEN {$getNameExpr('roas_purchase')} AND $periodCondition THEN $valCol ELSE NULL END)",
                'actions'                        => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'actions'" : "mc.name = 'actions'")." AND $periodCondition THEN $valCol ELSE 0 END)",
                'campaign_status'                => 'MIN(rcc.status)',
                'purchase_roas'                  => "AVG(CASE WHEN {$getNameExpr('roas_purchase')} AND $periodCondition THEN $valCol ELSE NULL END)",
                'website_purchase_roas'          => "AVG(CASE WHEN {$getNameExpr('roas_purchase')} AND $periodCondition THEN $valCol ELSE NULL END)",
                'total_interactions'             => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'profile_views'                  => "SUM(CASE WHEN {$getNameExpr('profile_views')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'follower_count'                 => "SUM(CASE WHEN {$getNameExpr('follower_count')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'page_impressions'               => "SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'page_post_engagements'          => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'page_views_total'               => "SUM(CASE WHEN {$getNameExpr('page_views_total')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'page_fans'                      => "SUM(CASE WHEN {$getNameExpr('follower_count')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'post_impressions'               => "SUM(CASE WHEN {$getNameExpr('impressions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'post_engagement'                => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'likes'                          => "SUM(CASE WHEN {$getNameExpr('likes')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'comments'                       => "SUM(CASE WHEN {$getNameExpr('comments')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'shares'                         => "SUM(CASE WHEN {$getNameExpr('shares')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'saves'                          => "SUM(CASE WHEN {$getNameExpr('saves')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'saved'                          => "SUM(CASE WHEN {$getNameExpr('saves')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'plays'                          => "SUM(CASE WHEN {$getNameExpr('views')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'views'                          => "SUM(CASE WHEN {$getNameExpr('views')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'engagement'                     => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'video_views'                    => "SUM(CASE WHEN {$getNameExpr('views')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'website_clicks'                 => "SUM(CASE WHEN {$getNameExpr('website_clicks')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'profile_links_taps'             => "SUM(CASE WHEN {$getNameExpr('profile_links_taps')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'follows_and_unfollows'          => "SUM(CASE WHEN {$getNameExpr('follows_and_unfollows')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'replies'                        => "SUM(CASE WHEN {$getNameExpr('comments')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'accounts_engaged'               => "SUM(CASE WHEN {$getNameExpr('engagement')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'follows'                        => "SUM(CASE WHEN {$getNameExpr('follower_count')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'ig_reels_avg_watch_time'        => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'ig_reels_avg_watch_time'" : "mc.name = 'ig_reels_avg_watch_time'")." AND $periodCondition THEN $valCol ELSE 0 END)",
                'ig_reels_video_view_total_time' => "SUM(CASE WHEN ".($isPostgres ? "LOWER(mc.name) = 'ig_reels_video_view_total_time'" : "mc.name = 'ig_reels_video_view_total_time'")." AND $periodCondition THEN $valCol ELSE 0 END)",
                'profile_activity'               => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'reposts'                        => "SUM(CASE WHEN {$getNameExpr('shares')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'post_clicks'                    => "SUM(CASE WHEN {$getNameExpr('clicks')} AND $periodCondition THEN $valCol ELSE 0 END)",
                'post_engagements'               => "SUM(CASE WHEN {$getNameExpr('total_interactions')} AND $periodCondition THEN $valCol ELSE 0 END)",
            ];
        }
    }
