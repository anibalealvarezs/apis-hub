<?php

declare(strict_types=1);

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;

class FacebookMarketingConvert
{
    /**
     * @param array $campaigns
     * @param int|string|null $channeledAccountId
     * @return ArrayCollection
     */
    public static function campaigns(array $campaigns, int|string|null $channeledAccountId = null): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($campaign) use ($channeledAccountId) {
            return (object) [
                'platformId' => $campaign['id'] ?? null,
                'name' => $campaign['name'] ?? '',
                'startDate' => isset($campaign['start_time']) ? Carbon::parse($campaign['start_time']) : null,
                'endDate' => isset($campaign['stop_time']) ? Carbon::parse($campaign['stop_time']) : null,
                'objective' => $campaign['objective'] ?? null,
                'buyingType' => $campaign['buying_type'] ?? null,
                'status' => $campaign['status'] ?? null,
                'budget' => $campaign['daily_budget'] ?? $campaign['lifetime_budget'] ?? 0,
                'channel' => Channel::facebook_marketing->value,
                'channeledAccountId' => $channeledAccountId,
                'data' => $campaign,
            ];
        }, $campaigns));
    }

    /**
     * @param array $adsets
     * @param int|string|null $channeledAccountId
     * @return ArrayCollection
     */
    public static function adsets(array $adsets, int|string|null $channeledAccountId = null): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($adset) use ($channeledAccountId) {
            return (object) [
                'platformId' => $adset['id'] ?? null,
                'name' => $adset['name'] ?? '',
                'startDate' => isset($adset['start_time']) ? Carbon::parse($adset['start_time']) : null,
                'endDate' => isset($adset['stop_time']) ? Carbon::parse($adset['stop_time']) : null,
                'status' => $adset['status'] ?? null,
                'optimizationGoal' => $adset['optimization_goal'] ?? null,
                'billingEvent' => $adset['billing_event'] ?? null,
                'targeting' => $adset['targeting'] ?? null,
                'channel' => Channel::facebook_marketing->value,
                'channeledAccountId' => $channeledAccountId,
                'channeledCampaignId' => $adset['campaign_id'] ?? null, // platformId of campaign
                'data' => $adset,
            ];
        }, $adsets));
    }

    /**
     * @param array $ads
     * @param int|string|null $channeledAccountId
     * @return ArrayCollection
     */
    public static function ads(array $ads, int|string|null $channeledAccountId = null): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($ad) use ($channeledAccountId) {
            return (object) [
                'platformId' => $ad['id'] ?? null,
                'name' => $ad['name'] ?? '',
                'status' => $ad['status'] ?? null,
                'channel' => Channel::facebook_marketing->value,
                'channeledAccountId' => $channeledAccountId,
                'channeledCampaignId' => $ad['campaign_id'] ?? null, // platformId of campaign
                'channeledAdGroupId' => $ad['adset_id'] ?? null, // platformId of adset
                'data' => $ad,
            ];
        }, $ads));
    }
}
