<?php

declare(strict_types=1);

namespace Classes;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;

class SocialProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processPages(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $pages = $channeledCollection->toArray();

        $cols = ['url', 'canonical_id', 'title', 'hostname', 'platform_id', 'account_id', 'data'];
        $numCols = count($cols);
        $chunkSize = (int)floor(30000 / $numCols);

        foreach (array_chunk($pages, $chunkSize) as $chunk) {
            $params = [];
            foreach ($chunk as $p) {
                $params[] = $p->url;
                $params[] = $p->canonicalId ?? null;
                $params[] = $p->title ?? null;
                $params[] = $p->hostname ?? null;
                $params[] = $p->platformId;
                $params[] = $p->accountId;
                $params[] = json_encode($p->data ?? []);
            }

            $sql = Helpers::buildUpsertSql(
                'pages', 
                $cols, 
                ['url', 'title', 'hostname', 'platform_id', 'data', 'account_id'], 
                'canonical_id', 
                count($chunk)
            );
            $conn->executeStatement($sql, $params);
        }
    }

    /**
     * @param \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity $entity
     * @param EntityManager $manager
     * @return void
     */
    public static function processUniversalEntity(\Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity $entity, EntityManager $manager): void
    {
        $type = $entity->getType();
        $channel = $entity->getChannel();
        $context = $entity->getContext();

        if (in_array($type, ['pages', 'sites']) || !$type) {
            // It's a Page if it has a URL or is explicitly typed as such
            if ($entity->getUrl() || $entity->getCanonicalId()) {
                self::processPageEntity($entity, $manager);
            }
        }

        // Always check if it's a ChanneledAccount as well (or exclusively)
        if ($channel && $type) {
            self::processChanneledAccount($entity, $manager);
        }
    }

    private static function processPageEntity(\Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity $entity, EntityManager $manager): void
    {
        $conn = $manager->getConnection();
        $cols = ['url', 'canonical_id', 'title', 'hostname', 'platform_id', 'account_id', 'data'];
        
        $account = $entity->getContext()['account'] ?? null;
        $accountId = is_object($account) ? $account->getId() : $account;

        $params = [
            (string)$entity->getUrl(),
            (string)($entity->getCanonicalId() ?? $entity->getPlatformId()),
            (string)$entity->getTitle(),
            (string)$entity->getHostname(),
            (string)$entity->getPlatformId(),
            $accountId,
            json_encode($entity->getData() ?? [])
        ];

        $sql = Helpers::buildUpsertSql('pages', $cols, ['url', 'title', 'hostname', 'platform_id', 'data', 'account_id'], 'canonical_id', 1);
        $conn->executeStatement($sql, $params);
    }

    private static array $channelMap = [];

    private static function resolveChannelId(string $channelName, EntityManager $manager): int
    {
        if (isset(self::$channelMap[$channelName])) {
            return self::$channelMap[$channelName];
        }

        $channel = $manager->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channelName]);
        if (!$channel) {
            throw new \Exception("Channel '$channelName' not found in database during social processing.");
        }

        self::$channelMap[$channelName] = $channel->getId();
        return self::$channelMap[$channelName];
    }

    private static function processChanneledAccount(\Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity $entity, EntityManager $manager): void
    {
        $conn = $manager->getConnection();
        $cols = ['platform_id', 'account_id', 'channel', 'name', 'type', 'data'];

        $account = $entity->getContext()['account'] ?? null;
        $accountId = is_object($account) ? $account->getId() : $account;

        $params = [
            (string)$entity->getPlatformId(),
            $accountId,
            self::resolveChannelId((string)$entity->getChannel(), $manager),
            (string)($entity->getTitle() ?? $entity->getPlatformId()),
            (string)$entity->getType(),
            json_encode($entity->getData() ?? [])
        ];

        $sql = Helpers::buildUpsertSql('channeled_accounts', $cols, ['name', 'type', 'data', 'account_id'], ['platform_id', 'channel'], 1);
        $conn->executeStatement($sql, $params);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processPosts(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $posts = $channeledCollection->toArray();

        $cols = ['post_id', 'page_id', 'account_id', 'channeled_account_id', 'data'];
        $numCols = count($cols);
        $chunkSize = (int)floor(64000 / $numCols);

        foreach (array_chunk($posts, $chunkSize) as $chunk) {
            $params = [];
            foreach ($chunk as $p) {
                $params[] = $p->platformId;
                $params[] = $p->pageId;
                $params[] = $p->accountId;
                $params[] = $p->channeledAccountId ?? null;
                $params[] = json_encode($p->data ?? []);
            }

            $sql = Helpers::buildUpsertSql(
                'posts', 
                $cols, 
                ['data', 'channeled_account_id', 'account_id', 'page_id'], 
                ['post_id', 'page_id', 'account_id', 'channeled_account_id'], 
                count($chunk)
            );
            $conn->executeStatement($sql, $params);
        }
    }
}
