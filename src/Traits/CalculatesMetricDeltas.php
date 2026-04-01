<?php

declare(strict_types=1);

namespace Traits;

use Carbon\Carbon;
use Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Enums\Period;
use stdClass;

trait CalculatesMetricDeltas
{
    /**
     * Identifies lifetime metrics in the collection and adds virtual daily counterparts.
     *
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return void
     */
    public static function injectVirtualDailyMetrics(ArrayCollection $metrics, EntityManager $manager): void
    {
        $lifetimeMetrics = [];
        foreach ($metrics as $metric) {
            // Only process lifetime metrics that don't have a virtual counterpart yet
            if (($metric->period ?? null) === Period::Lifetime->value) {
                $lifetimeMetrics[] = $metric;
            }
        }

        if (empty($lifetimeMetrics)) {
            return;
        }

        // 1. Generate "Yesterday Signatures" for all lifetime metrics
        $yesterdaySignatures = [];
        foreach ($lifetimeMetrics as $metric) {
            $yesterdayDate = Carbon::parse($metric->metricDate)->subDay()->toDateString();
            
            $yesterdaySignature = KeyGenerator::generateMetricConfigKey(
                channel: $metric->channel,
                name: $metric->name,
                period: $metric->period,
                metricDate: $yesterdayDate,
                account: isset($metric->account) ? $metric->account->getName() : null,
                channeledAccount: isset($metric->channeledAccount) ? (string) $metric->channeledAccount->getPlatformId() : (isset($metric->channeledAccountPlatformId) ? (string)$metric->channeledAccountPlatformId : null),
                campaign: isset($metric->campaign) ? (string) $metric->campaign->getCampaignId() : (isset($metric->campaignPlatformId) ? (string)$metric->campaignPlatformId : null),
                channeledCampaign: isset($metric->channeledCampaign) ? (string) $metric->channeledCampaign->getPlatformId() : (isset($metric->channeledCampaignPlatformId) ? (string)$metric->channeledCampaignPlatformId : null),
                channeledAdGroup: isset($metric->channeledAdGroup) ? $metric->channeledAdGroup->getPlatformId() : (isset($metric->channeledAdGroupPlatformId) ? (string)$metric->channeledAdGroupPlatformId : null),
                channeledAd: isset($metric->channeledAd) ? $metric->channeledAd->getPlatformId() : (isset($metric->channeledAdPlatformId) ? (string)$metric->channeledAdPlatformId : null),
                page: isset($metric->page) ? $metric->page->getUrl() : null,
                query: $metric->query ?? null,
                post: isset($metric->post) ? $metric->post->getPostId() : null,
                product: isset($metric->product) ? $metric->product->getProductId() : null,
                customer: isset($metric->customer) ? $metric->customer->getEmail() : null,
                order: isset($metric->order) ? $metric->order->getOrderId() : null,
                country: $metric->countryCode ?? null,
                device: $metric->deviceType ?? null,
                creative: isset($metric->creative) ? $metric->creative->getCreativeId() : null,
            );
            
            $metric->yesterdaySignature = $yesterdaySignature;
            $yesterdaySignatures[] = $yesterdaySignature;
        }

        // 2. Batch lookup previous values from database
        $previousValuesMap = [];
        if (!empty($yesterdaySignatures)) {
            $chunks = array_chunk($yesterdaySignatures, 1000);
            foreach ($chunks as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $sql = "SELECT mc.config_signature, m.value 
                        FROM metrics m 
                        JOIN metric_configs mc ON m.metric_config_id = mc.id 
                        WHERE mc.config_signature IN ($placeholders)";
                
                $results = $manager->getConnection()->executeQuery($sql, $chunk)->fetchAllAssociative();
                foreach ($results as $row) {
                    $previousValuesMap[$row['config_signature']] = (float)$row['value'];
                }
            }
        }

        // 3. Create and inject virtual DAILY metrics
        foreach ($lifetimeMetrics as $metric) {
            $prevValue = $previousValuesMap[$metric->yesterdaySignature] ?? 0;
            $delta = (float)$metric->value - $prevValue;
            
            // Create daily virtual metric
            $virtual = clone $metric;
            $virtual->name = $metric->name . '_daily';
            $virtual->period = Period::Daily->value;
            $virtual->value = max(0, $delta);
            $virtual->isVirtualDelta = true;
            
            // Re-generate the config key for the new daily metric
            $virtual->metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: $virtual->channel,
                name: $virtual->name,
                period: $virtual->period,
                metricDate: $virtual->metricDate,
                account: isset($virtual->account) ? $virtual->account->getName() : null,
                channeledAccount: isset($virtual->channeledAccount) ? (string) $virtual->channeledAccount->getPlatformId() : (isset($virtual->channeledAccountPlatformId) ? (string)$virtual->channeledAccountPlatformId : null),
                campaign: isset($virtual->campaign) ? (string) $virtual->campaign->getCampaignId() : (isset($virtual->campaignPlatformId) ? (string)$virtual->campaignPlatformId : null),
                channeledCampaign: isset($virtual->channeledCampaign) ? (string) $virtual->channeledCampaign->getPlatformId() : (isset($virtual->channeledCampaignPlatformId) ? (string)$virtual->channeledCampaignPlatformId : null),
                channeledAdGroup: isset($virtual->channeledAdGroup) ? $virtual->channeledAdGroup->getPlatformId() : (isset($virtual->channeledAdGroupPlatformId) ? (string)$virtual->channeledAdGroupPlatformId : null),
                channeledAd: isset($virtual->channeledAd) ? $virtual->channeledAd->getPlatformId() : (isset($virtual->channeledAdPlatformId) ? (string)$virtual->channeledAdPlatformId : null),
                page: isset($virtual->page) ? $virtual->page->getUrl() : null,
                query: $virtual->query ?? null,
                post: isset($virtual->post) ? $virtual->post->getPostId() : null,
                product: isset($virtual->product) ? $virtual->product->getProductId() : null,
                customer: isset($virtual->customer) ? $virtual->customer->getEmail() : null,
                order: isset($virtual->order) ? $virtual->order->getOrderId() : null,
                country: $virtual->countryCode ?? null,
                device: $virtual->deviceType ?? null,
                creative: isset($virtual->creative) ? $virtual->creative->getCreativeId() : null,
            );

            $metrics->add($virtual);
        }
    }
}
