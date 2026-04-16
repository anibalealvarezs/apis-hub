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
        $campaignIds = [];
        foreach ($campaigns as $c) {
            if (!$c instanceof \stdClass) continue;
            /** @var \stdClass $c */
            $campaignIds[] = $c->platformId;
        }

        if (!empty($campaigns)) {
            $cols = ['campaign_id', 'name', 'start_date', 'end_date'];
            $numCols = count($cols);
            $chunkSize = (int)floor(30000 / $numCols);

            foreach (array_chunk($campaigns, $chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $c) {
                    if (!$c instanceof \stdClass) continue;
                    /** @var \stdClass $c */
                    $params[] = $c->platformId;
                    $params[] = $c->name;
                    $params[] = $c->startDate ? $c->startDate->toDateTimeString() : null;
                    $params[] = $c->endDate ? $c->endDate->toDateTimeString() : null;
                }
                $sql = Helpers::buildUpsertSql(
                    'campaigns', 
                    $cols, 
                    ['name', 'start_date', 'end_date'], 
                    'campaign_id', 
                    count($chunk)
                );
                $conn->executeStatement($sql, $params);
            }
            self::getLogger()->info("Processed " . count($campaigns) . " campaigns");
        }

        // 2. Fetch campaign IDs map
        $campaignMap = [];
        foreach (array_chunk($campaignIds, 1000) as $chunk) {
            $sqlMap = 'SELECT id, campaign_id FROM campaigns WHERE campaign_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            $fetched = $conn->executeQuery($sqlMap, $chunk)->fetchAllAssociative();
            foreach ($fetched as $row) {
                $campaignMap[$row['campaign_id']] = $row['id'];
            }
        }

        if (!empty($campaigns)) {
            $cols = ['channel', 'platform_id', 'campaign_id', 'channeled_account_id', 'budget', 'status', 'objective', 'buying_type', 'data'];
            $numCols = count($cols);
            $chunkSize = (int)floor(30000 / $numCols);

            foreach (array_chunk($campaigns, $chunkSize) as $chunk) {
                $channeledParams = [];
                foreach ($chunk as $c) {
                    if (!$c instanceof \stdClass) continue;
                    /** @var \stdClass $c */
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
                $sql = Helpers::buildUpsertSql(
                    'channeled_campaigns', 
                    $cols, 
                    ['budget', 'status', 'objective', 'buying_type', 'data'], 
                    ['platform_id', 'channeled_account_id'], 
                    count($chunk)
                );
                $affected = $conn->executeStatement($sql, $channeledParams);
                self::getLogger()->info("Inserted/Updated " . count($chunk) . " channeled campaigns: $affected rows affected");
            }
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
        if (!empty($adsets)) {
            $cols = ['channel', 'platform_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'name', 'start_date', 'end_date', 'status', 'optimization_goal', 'billing_event', 'targeting', 'data'];
            $numCols = count($cols);
            $chunkSize = (int)floor(30000 / $numCols);

            foreach (array_chunk($adsets, $chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $a) {
                    if (!$a instanceof \stdClass) continue;
                    /** @var \stdClass $a */
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
                $sql = Helpers::buildUpsertSql(
                    'channeled_ad_groups', 
                    $cols, 
                    ['campaign_id', 'channeled_campaign_id', 'name', 'status', 'targeting', 'data'], 
                    ['platform_id', 'channeled_account_id'], 
                    count($chunk)
                );
                $affected = $conn->executeStatement($sql, $params);
                self::getLogger()->info("Inserted/Updated " . count($chunk) . " channeled ad groups: $affected rows affected");
            }
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
        if (!empty($ads)) {
            $cols = ['channel', 'platform_id', 'channeled_account_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'creative_id', 'name', 'status', 'data'];
            $numCols = count($cols);
            $chunkSize = (int)floor(30000 / $numCols);

            foreach (array_chunk($ads, $chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $a) {
                    if (!$a instanceof \stdClass) continue;
                    /** @var \stdClass $a */
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
                $sql = Helpers::buildUpsertSql(
                    'channeled_ads', 
                    $cols, 
                    ['channeled_account_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'creative_id', 'name', 'status', 'data'], 
                    ['platform_id', 'channeled_campaign_id'], 
                    count($chunk)
                );
                $affected = $conn->executeStatement($sql, $params);
                self::getLogger()->info("Inserted/Updated " . count($chunk) . " channeled ads: $affected rows affected");
            }
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
        if (!empty($creatives)) {
            $cols = ['creative_id', 'name', 'data'];
            $numCols = count($cols);
            $chunkSize = (int)floor(64000 / $numCols);

            foreach (array_chunk($creatives, $chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $c) {
                    if (!$c instanceof \stdClass) continue;
                    /** @var \stdClass $c */
                    $params[] = $c->platformId;
                    $params[] = $c->name;
                    $params[] = json_encode($c->data);
                }
                $sql = Helpers::buildUpsertSql(
                    'creatives', 
                    $cols, 
                    ['name', 'data'], 
                    'creative_id', 
                    count($chunk)
                );
                $conn->executeStatement($sql, $params);
            }
            self::getLogger()->info("Processed " . count($creatives) . " creatives");
        }
    }
}
