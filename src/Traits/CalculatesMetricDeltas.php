<?php

declare(strict_types=1);

namespace Traits;

use Carbon\Carbon;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
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
            if (!$metric instanceof \stdClass) continue;
            /** @var \stdClass $metric */
            if (($metric->period ?? null) === Period::Lifetime->value) {
                $lifetimeMetrics[] = $metric;
            }
        }

        if (empty($lifetimeMetrics)) {
            return;
        }

        // 1. Generate "Yesterday Signatures" for all lifetime metrics
        $signatures = [];
        foreach ($lifetimeMetrics as $metric) {
            $yesterdayDate = Carbon::parse($metric->metricDate)->subDay()->toDateString();
            
            $signature = KeyGenerator::generateMetricConfigKey(
                channel: $metric->channel,
                name: $metric->name,
                period: $metric->period,
                account: isset($metric->account) ? (is_object($metric->account) ? $metric->account->getName() : (string)$metric->account) : null,
                channeledAccount: isset($metric->channeledAccount) ? (is_object($metric->channeledAccount) ? (string)$metric->channeledAccount->getPlatformId() : (string)$metric->channeledAccount) : (isset($metric->channeledAccountPlatformId) ? (string)$metric->channeledAccountPlatformId : null),
                campaign: isset($metric->campaign) ? (is_object($metric->campaign) ? (string)$metric->campaign->getCampaignId() : (string)$metric->campaign) : (isset($metric->campaignPlatformId) ? (string)$metric->campaignPlatformId : null),
                channeledCampaign: isset($metric->channeledCampaign) ? (is_object($metric->channeledCampaign) ? (string)$metric->channeledCampaign->getPlatformId() : (string)$metric->channeledCampaign) : (isset($metric->channeledCampaignPlatformId) ? (string)$metric->channeledCampaignPlatformId : null),
                channeledAdGroup: isset($metric->channeledAdGroup) ? (is_object($metric->channeledAdGroup) ? $metric->channeledAdGroup->getPlatformId() : (string)$metric->channeledAdGroup) : (isset($metric->channeledAdGroupPlatformId) ? (string)$metric->channeledAdGroupPlatformId : null),
                channeledAd: isset($metric->channeledAd) ? (is_object($metric->channeledAd) ? $metric->channeledAd->getPlatformId() : (string)$metric->channeledAd) : (isset($metric->channeledAdPlatformId) ? (string)$metric->channeledAdPlatformId : null),
                page: isset($metric->page) ? (is_object($metric->page) ? $metric->page->getUrl() : (string)$metric->page) : null,
                query: $metric->query ?? null,
                post: isset($metric->post) ? (is_object($metric->post) ? $metric->post->getPostId() : (string)$metric->post) : null,
                product: isset($metric->product) ? (is_object($metric->product) ? $metric->product->getProductId() : (string)$metric->product) : null,
                customer: isset($metric->customer) ? (is_object($metric->customer) ? $metric->customer->getEmail() : (string)$metric->customer) : null,
                order: isset($metric->order) ? (is_object($metric->order) ? $metric->order->getOrderId() : (string)$metric->order) : null,
                country: $metric->countryCode ?? null,
                device: $metric->deviceType ?? null,
                creative: isset($metric->creative) ? (is_object($metric->creative) ? $metric->creative->getCreativeId() : (string)$metric->creative) : null,
                dimensionSet: $metric->dimensionsHash ?? null,
            );
            
            $metric->atemporalSignature = $signature;
            $metric->yesterdayDate = $yesterdayDate;
            $signatures[] = $signature;
        }

        // 2. Batch lookup previous values from database
        $previousValuesMap = [];
        if (!empty($signatures)) {
            $chunks = array_chunk($lifetimeMetrics, 1000);
            foreach ($chunks as $chunk) {
                $params = [];
                $tuples = [];
                foreach ($chunk as $m) {
                    $params[] = $m->atemporalSignature;
                    $params[] = $m->yesterdayDate;
                    $tuples[] = '(?, ?)';
                }
                $placeholders = implode(', ', $tuples);
                $sql = "SELECT mc.config_signature, m.metric_date, m.value 
                        FROM metrics m 
                        JOIN metric_configs mc ON m.metric_config_id = mc.id 
                        WHERE (mc.config_signature, m.metric_date) IN ($placeholders)";
                
                $results = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
                foreach ($results as $row) {
                    $key = $row['config_signature'] . '|' . $row['metric_date'];
                    $previousValuesMap[$key] = (float)$row['value'];
                }
            }
        }

        // 3. Create and inject virtual DAILY metrics
        foreach ($lifetimeMetrics as $metric) {
            $lookupKey = $metric->atemporalSignature . '|' . $metric->yesterdayDate;
            $prevValue = $previousValuesMap[$lookupKey] ?? 0;
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
                account: isset($virtual->account) ? (is_object($virtual->account) ? $virtual->account->getName() : (string)$virtual->account) : null,
                channeledAccount: isset($virtual->channeledAccount) ? (is_object($virtual->channeledAccount) ? (string)$virtual->channeledAccount->getPlatformId() : (string)$virtual->channeledAccount) : (isset($virtual->channeledAccountPlatformId) ? (string)$virtual->channeledAccountPlatformId : null),
                campaign: isset($virtual->campaign) ? (is_object($virtual->campaign) ? (string)$virtual->campaign->getCampaignId() : (string)$virtual->campaign) : (isset($virtual->campaignPlatformId) ? (string)$virtual->campaignPlatformId : null),
                channeledCampaign: isset($virtual->channeledCampaign) ? (is_object($virtual->channeledCampaign) ? (string)$virtual->channeledCampaign->getPlatformId() : (string)$virtual->channeledCampaign) : (isset($virtual->channeledCampaignPlatformId) ? (string)$virtual->channeledCampaignPlatformId : null),
                channeledAdGroup: isset($virtual->channeledAdGroup) ? (is_object($virtual->channeledAdGroup) ? $virtual->channeledAdGroup->getPlatformId() : (string)$virtual->channeledAdGroup) : (isset($virtual->channeledAdGroupPlatformId) ? (string)$virtual->channeledAdGroupPlatformId : null),
                channeledAd: isset($virtual->channeledAd) ? (is_object($virtual->channeledAd) ? $virtual->channeledAd->getPlatformId() : (string)$virtual->channeledAd) : (isset($virtual->channeledAdPlatformId) ? (string)$virtual->channeledAdPlatformId : null),
                page: isset($virtual->page) ? (is_object($virtual->page) ? $virtual->page->getUrl() : (string)$virtual->page) : null,
                query: $virtual->query ?? null,
                post: isset($virtual->post) ? (is_object($virtual->post) ? $virtual->post->getPostId() : (string)$virtual->post) : null,
                product: isset($virtual->product) ? (is_object($virtual->product) ? $virtual->product->getProductId() : (string)$virtual->product) : null,
                customer: isset($virtual->customer) ? (is_object($virtual->customer) ? $virtual->customer->getEmail() : (string)$virtual->customer) : null,
                order: isset($virtual->order) ? (is_object($virtual->order) ? $virtual->order->getOrderId() : (string)$virtual->order) : null,
                country: $virtual->countryCode ?? null,
                device: $virtual->deviceType ?? null,
                creative: isset($virtual->creative) ? (is_object($virtual->creative) ? $virtual->creative->getCreativeId() : (string)$virtual->creative) : null,
                dimensionSet: $virtual->dimensionsHash ?? null,
            );

            $metrics->add($virtual);
        }
    }
}
