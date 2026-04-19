<?php

declare(strict_types=1);

namespace Traits;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
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
            if (! is_object($metric)) {
                continue;
            }
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
                account: ($rowAccount = $metric->account ?? $metric->accountPlatformId ?? null) ? (is_object($rowAccount) ? $rowAccount->getName() : (string)$rowAccount) : null,
                channeledAccount: ($rowCa = $metric->channeledAccount ?? $metric->channeledAccountPlatformId ?? null) ? (is_object($rowCa) ? (string)$rowCa->getPlatformId() : (string)$rowCa) : null,
                campaign: ($rowC = $metric->campaign ?? $metric->campaignPlatformId ?? null) ? (is_object($rowC) ? (string)$rowC->getCampaignId() : (string)$rowC) : null,
                channeledCampaign: ($rowCc = $metric->channeledCampaign ?? $metric->channeledCampaignPlatformId ?? null) ? (is_object($rowCc) ? (string)$rowCc->getPlatformId() : (string)$rowCc) : null,
                channeledAdGroup: ($rowCag = $metric->channeledAdGroup ?? $metric->channeledAdGroupPlatformId ?? null) ? (is_object($rowCag) ? $rowCag->getPlatformId() : (string)$rowCag) : null,
                channeledAd: ($rowCad = $metric->channeledAd ?? $metric->channeledAdPlatformId ?? null) ? (is_object($rowCad) ? $rowCad->getPlatformId() : (string)$rowCad) : null,
                page: ($rowP = $metric->page ?? $metric->pagePlatformId ?? null) ? (is_object($rowP) ? $rowP->getUrl() : (string)$rowP) : null,
                query: $metric->query ?? null,
                post: ($rowPost = $metric->post ?? $metric->postPlatformId ?? null) ? (is_object($rowPost) ? (method_exists($rowPost, 'getPostId') ? $rowPost->getPostId() : (method_exists($rowPost, 'getPlatformId') ? $rowPost->getPlatformId() : (string)$rowPost)) : (string)$rowPost) : null,
                product: ($rowPr = $metric->product ?? $metric->productPlatformId ?? null) ? (is_object($rowPr) ? $rowPr->getProductId() : (string)$rowPr) : null,
                customer: ($rowCu = $metric->customer ?? $metric->customerPlatformId ?? null) ? (is_object($rowCu) ? $rowCu->getEmail() : (string)$rowCu) : null,
                order: ($rowO = $metric->order ?? $metric->orderPlatformId ?? null) ? (is_object($rowO) ? $rowO->getOrderId() : (string)$rowO) : null,
                country: $metric->countryCode ?? $metric->country ?? null,
                device: $metric->deviceType ?? $metric->device ?? null,
                creative: ($rowCre = $metric->creative ?? $metric->creativePlatformId ?? null) ? (is_object($rowCre) ? $rowCre->getCreativeId() : (string)$rowCre) : null,
                dimensionSet: $metric->dimensionsHash ?? null,
            );

            $metric->atemporalSignature = $signature;
            $metric->yesterdayDate = $yesterdayDate;
            $signatures[] = $signature;
        }

        // 2. Batch lookup previous values from database
        $previousValuesMap = [];
        if (! empty($signatures)) {
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
                account: ($rowAccount = $virtual->account ?? $virtual->accountPlatformId ?? null) ? (is_object($rowAccount) ? $rowAccount->getName() : (string)$rowAccount) : null,
                channeledAccount: ($rowCa = $virtual->channeledAccount ?? $virtual->channeledAccountPlatformId ?? null) ? (is_object($rowCa) ? (string)$rowCa->getPlatformId() : (string)$rowCa) : null,
                campaign: ($rowC = $virtual->campaign ?? $virtual->campaignPlatformId ?? null) ? (is_object($rowC) ? (string)$rowC->getCampaignId() : (string)$rowC) : null,
                channeledCampaign: ($rowCc = $virtual->channeledCampaign ?? $virtual->channeledCampaignPlatformId ?? null) ? (is_object($rowCc) ? (string)$rowCc->getPlatformId() : (string)$rowCc) : null,
                channeledAdGroup: ($rowCag = $virtual->channeledAdGroup ?? $virtual->channeledAdGroupPlatformId ?? null) ? (is_object($rowCag) ? $rowCag->getPlatformId() : (string)$rowCag) : null,
                channeledAd: ($rowCad = $virtual->channeledAd ?? $virtual->channeledAdPlatformId ?? null) ? (is_object($rowCad) ? $rowCad->getPlatformId() : (string)$rowCad) : null,
                page: ($rowP = $virtual->page ?? $virtual->pagePlatformId ?? null) ? (is_object($rowP) ? $rowP->getUrl() : (string)$rowP) : null,
                query: $virtual->query ?? null,
                post: ($rowPost = $virtual->post ?? $virtual->postPlatformId ?? null) ? (is_object($rowPost) ? (method_exists($rowPost, 'getPostId') ? $rowPost->getPostId() : (method_exists($rowPost, 'getPlatformId') ? $rowPost->getPlatformId() : (string)$rowPost)) : (string)$rowPost) : null,
                product: ($rowPr = $virtual->product ?? $virtual->productPlatformId ?? null) ? (is_object($rowPr) ? $rowPr->getProductId() : (string)$rowPr) : null,
                customer: ($rowCu = $virtual->customer ?? $virtual->customerPlatformId ?? null) ? (is_object($rowCu) ? $rowCu->getEmail() : (string)$rowCu) : null,
                order: ($rowO = $virtual->order ?? $virtual->orderPlatformId ?? null) ? (is_object($rowO) ? $rowO->getOrderId() : (string)$rowO) : null,
                country: $virtual->countryCode ?? $virtual->country ?? null,
                device: $virtual->deviceType ?? $virtual->device ?? null,
                creative: ($rowCre = $virtual->creative ?? $virtual->creativePlatformId ?? null) ? (is_object($rowCre) ? $rowCre->getCreativeId() : (string)$rowCre) : null,
                dimensionSet: $virtual->dimensionsHash ?? null,
            );

            $metrics->add($virtual);
        }
    }
}
