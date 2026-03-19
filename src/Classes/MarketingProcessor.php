<?php

declare(strict_types=1);

namespace Classes;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;

class MarketingProcessor
{
    private static function getLogger(): \Psr\Log\LoggerInterface
    {
        return Helpers::setLogger('marketing-processor.log');
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processCampaigns(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $campaigns = $channeledCollection->toArray();

        // 1. Bulk Insert/Update campaigns (Global)
        $insertParams = [];
        $campaignIds = [];
        foreach ($campaigns as $c) {
            $insertParams[] = $c->platformId;
            $insertParams[] = $c->name;
            $insertParams[] = $c->startDate ? $c->startDate->toDateTimeString() : null;
            $insertParams[] = $c->endDate ? $c->endDate->toDateTimeString() : null;
            $campaignIds[] = $c->platformId;
        }

        if (!empty($insertParams)) {
            $sql = Helpers::buildUpsertSql(
                'campaigns', 
                ['campaign_id', 'name', 'start_date', 'end_date'], 
                ['name', 'start_date', 'end_date'], 
                'campaign_id', 
                count($campaigns)
            );
            $conn->executeStatement($sql, $insertParams);
            self::getLogger()->info("Processed " . count($campaigns) . " campaigns");
        }

        // 2. Fetch campaign IDs map
        $sqlMap = 'SELECT id, campaign_id FROM campaigns WHERE campaign_id IN ('
            . implode(', ', array_fill(0, count($campaignIds), '?')) . ')';
        $fetched = $conn->executeQuery($sqlMap, $campaignIds)->fetchAllAssociative();
        $campaignMap = [];
        foreach ($fetched as $row) {
            $campaignMap[$row['campaign_id']] = $row['id'];
        }

        // 3. Bulk Insert/Update channeled_campaigns
        $channeledParams = [];
        foreach ($campaigns as $c) {
            $channeledParams[] = $c->channel;
            $channeledParams[] = $c->platformId;
            $channeledParams[] = $campaignMap[$c->platformId] ?? null;
            $channeledParams[] = $c->channeledAccountId;
            $channeledParams[] = (float)($c->budget ?? 0);
            $channeledParams[] = $c->status ?? null;
            $channeledParams[] = $c->objective ?? null;
            $channeledParams[] = $c->buyingType ?? null;
            $channeledParams[] = json_encode($c->data);
        }

        if (!empty($channeledParams)) {
            $sql = Helpers::buildUpsertSql(
                'channeled_campaigns', 
                ['channel', 'platform_id', 'campaign_id', 'channeled_account_id', 'budget', 'status', 'objective', 'buying_type', 'data'], 
                ['budget', 'status', 'objective', 'buying_type', 'data'], 
                ['platform_id', 'channeled_account_id'], 
                count($campaigns)
            );
            $conn->executeStatement($sql, $channeledParams);
            self::getLogger()->info("Processed " . count($campaigns) . " channeled campaigns");
        }
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processAdGroups(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $adsets = $channeledCollection->toArray();

        // 1. Fetch relevant maps (Campaigns)
        $campaignPlatformIds = array_unique(array_filter(array_column($adsets, 'channeledCampaignId')));
        $campaignMap = [];
        if (!empty($campaignPlatformIds)) {
            // We need to map platform_id (campaign_id in FB) to our DB ids for channeled_campaigns and global campaigns
            // For AdGroup, we need channeledCampaign_id and campaign_id
            $sql = 'SELECT platform_id, id, campaign_id FROM channeled_campaigns WHERE platform_id IN ('
                . implode(', ', array_fill(0, count($campaignPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($campaignPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $campaignMap[$row['platform_id']] = [
                    'channeled_id' => $row['id'],
                    'global_id' => $row['campaign_id']
                ];
            }
        }

        // 2. Bulk Insert/Update channeled_ad_groups
        $params = [];
        foreach ($adsets as $a) {
            $params[] = $a->channel;
            $params[] = $a->platformId;
            $params[] = $a->channeledAccountId;
            $params[] = $campaignMap[$a->channeledCampaignId]['global_id'] ?? null;
            $params[] = $campaignMap[$a->channeledCampaignId]['channeled_id'] ?? null;
            $params[] = $a->name;
            $params[] = $a->startDate ? $a->startDate->toDateTimeString() : null;
            $params[] = $a->endDate ? $a->endDate->toDateTimeString() : null;
            $params[] = $a->status ?? null;
            $params[] = $a->optimizationGoal ?? null;
            $params[] = $a->billingEvent ?? null;
            $params[] = json_encode($a->targeting);
            $params[] = json_encode($a->data);
        }

        if (!empty($params)) {
            $sql = Helpers::buildUpsertSql(
                'channeled_ad_groups', 
                ['channel', 'platform_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'name', 'start_date', 'end_date', 'status', 'optimization_goal', 'billing_event', 'targeting', 'data'], 
                ['campaign_id', 'channeled_campaign_id', 'name', 'status', 'targeting', 'data'], 
                ['platform_id', 'channeled_account_id'], 
                count($adsets)
            );
            $conn->executeStatement($sql, $params);
            self::getLogger()->info("Processed " . count($adsets) . " ad groups");
        }
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processAds(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $ads = $channeledCollection->toArray();

        // 1. Fetch relevant maps (Campaigns, AdGroups & Creatives)
        $campaignPlatformIds = array_unique(array_filter(array_column($ads, 'channeledCampaignId')));
        $adsetPlatformIds = array_unique(array_filter(array_column($ads, 'channeledAdGroupId')));
        $creativePlatformIds = array_unique(array_filter(array_column($ads, 'channeledCreativeId')));

        $campaignMap = [];
        if (!empty($campaignPlatformIds)) {
            $sql = 'SELECT platform_id, id FROM channeled_campaigns WHERE platform_id IN ('
                . implode(', ', array_fill(0, count($campaignPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($campaignPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $campaignMap[$row['platform_id']] = $row['id'];
            }
        }

        $adGroupMap = [];
        if (!empty($adsetPlatformIds)) {
            $sql = 'SELECT platform_id, id FROM channeled_ad_groups WHERE platform_id IN ('
                . implode(', ', array_fill(0, count($adsetPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($adsetPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $adGroupMap[$row['platform_id']] = $row['id'];
            }
        }

        $creativeMap = [];
        if (!empty($creativePlatformIds)) {
            $sql = 'SELECT creative_id, id FROM creatives WHERE creative_id IN ('
                . implode(', ', array_fill(0, count($creativePlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($creativePlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $creativeMap[$row['creative_id']] = $row['id'];
            }
        }

        // 2. Bulk Insert/Update channeled_ads
        $params = [];
        foreach ($ads as $a) {
            $params[] = $a->channel;
            $params[] = $a->platformId;
            $params[] = $a->channeledAccountId;
            $params[] = $campaignMap[$a->channeledCampaignId] ?? null;
            $params[] = $adGroupMap[$a->channeledAdGroupId] ?? null;
            $params[] = $creativeMap[$a->channeledCreativeId] ?? null;
            $params[] = $a->name;
            $params[] = $a->status ?? null;
            $params[] = json_encode($a->data);
        }

        if (!empty($params)) {
            $sql = Helpers::buildUpsertSql(
                'channeled_ads', 
                ['channel', 'platform_id', 'channeled_account_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'creative_id', 'name', 'status', 'data'], 
                ['channeled_campaign_id', 'channeled_ad_group_id', 'creative_id', 'name', 'status', 'data'], 
                ['platform_id', 'channeled_campaign_id'], 
                count($ads)
            );
            $conn->executeStatement($sql, $params);
            self::getLogger()->info("Processed " . count($ads) . " ads");
        }
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processCreatives(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $creatives = $channeledCollection->toArray();

        // 1. Bulk Insert/Update creatives (Global)
        $insertParams = [];
        foreach ($creatives as $c) {
            $insertParams[] = $c->platformId;
            $insertParams[] = $c->name;
            $insertParams[] = json_encode($c->data);
        }

        if (!empty($insertParams)) {
            $sql = Helpers::buildUpsertSql(
                'creatives', 
                ['creative_id', 'name', 'data'], 
                ['name', 'data'], 
                'creative_id', 
                count($creatives)
            );
            $conn->executeStatement($sql, $insertParams);
            self::getLogger()->info("Processed " . count($creatives) . " creatives");
        }
    }
}
