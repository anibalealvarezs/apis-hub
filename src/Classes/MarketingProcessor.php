<?php

declare(strict_types=1);

namespace Classes;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;

class MarketingProcessor
{
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
            $sql = 'INSERT INTO campaigns (campaignId, name, startDate, endDate) VALUES '
                . implode(', ', array_fill(0, count($campaigns), '(?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE name = VALUES(name), startDate = VALUES(startDate), endDate = VALUES(endDate)';
            $conn->executeStatement($sql, $insertParams);
        }

        // 2. Fetch campaign IDs map
        $sqlMap = 'SELECT id, campaignId FROM campaigns WHERE campaignId IN ('
            . implode(', ', array_fill(0, count($campaignIds), '?')) . ')';
        $fetched = $conn->executeQuery($sqlMap, $campaignIds)->fetchAllAssociative();
        $campaignMap = [];
        foreach ($fetched as $row) {
            $campaignMap[$row['campaignId']] = $row['id'];
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
            $sql = 'INSERT INTO channeled_campaigns (channel, platformId, campaign_id, channeledAccount_id, budget, status, objective, buyingType, data) VALUES '
                . implode(', ', array_fill(0, count($campaigns), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE campaign_id = VALUES(campaign_id), budget = VALUES(budget), status = VALUES(status), objective = VALUES(objective), buyingType = VALUES(buyingType), data = VALUES(data)';
            $conn->executeStatement($sql, $channeledParams);
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
            // We need to map platformId (campaign_id in FB) to our DB ids for channeled_campaigns and global campaigns
            // For AdGroup, we need channeledCampaign_id and campaign_id
            $sql = 'SELECT platformId, id, campaign_id FROM channeled_campaigns WHERE platformId IN ('
                . implode(', ', array_fill(0, count($campaignPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($campaignPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $campaignMap[$row['platformId']] = [
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
            $sql = 'INSERT INTO channeled_ad_groups (channel, platformId, channeledAccount_id, campaign_id, channeledCampaign_id, name, startDate, endDate, status, optimizationGoal, billingEvent, targeting, data) VALUES '
                . implode(', ', array_fill(0, count($adsets), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE campaign_id = VALUES(campaign_id), channeledCampaign_id = VALUES(channeledCampaign_id), name = VALUES(name), status = VALUES(status), targeting = VALUES(targeting), data = VALUES(data)';
            $conn->executeStatement($sql, $params);
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

        // 1. Fetch relevant maps (Campaigns & AdGroups)
        $campaignPlatformIds = array_unique(array_filter(array_column($ads, 'channeledCampaignId')));
        $adsetPlatformIds = array_unique(array_filter(array_column($ads, 'channeledAdGroupId')));

        $campaignMap = [];
        if (!empty($campaignPlatformIds)) {
            $sql = 'SELECT platformId, id FROM channeled_campaigns WHERE platformId IN ('
                . implode(', ', array_fill(0, count($campaignPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($campaignPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $campaignMap[$row['platformId']] = $row['id'];
            }
        }

        $adGroupMap = [];
        if (!empty($adsetPlatformIds)) {
            $sql = 'SELECT platformId, id FROM channeled_ad_groups WHERE platformId IN ('
                . implode(', ', array_fill(0, count($adsetPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($adsetPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $adGroupMap[$row['platformId']] = $row['id'];
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
            $params[] = $a->name;
            $params[] = $a->status ?? null;
            $params[] = json_encode($a->data);
        }

        if (!empty($params)) {
            $sql = 'INSERT INTO channeled_ads (channel, platformId, channeledAccount_id, channeledCampaign_id, channeledAdGroup_id, name, status, data) VALUES '
                . implode(', ', array_fill(0, count($ads), '(?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE channeledCampaign_id = VALUES(channeledCampaign_id), channeledAdGroup_id = VALUES(channeledAdGroup_id), name = VALUES(name), status = VALUES(status), data = VALUES(data)';
            $conn->executeStatement($sql, $params);
        }
    }
}
