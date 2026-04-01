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
                ['url', 'title', 'platform_id', 'data'], 
                'canonical_id', 
                count($chunk)
            );
            $conn->executeStatement($sql, $params);
        }
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
