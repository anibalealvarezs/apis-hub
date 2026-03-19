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

        $params = [];
        foreach ($pages as $p) {
            $params[] = $p->url;
            $params[] = $p->title ?? null;
            $params[] = $p->hostname ?? null;
            $params[] = $p->platformId;
            $params[] = $p->accountId;
            $params[] = json_encode($p->data ?? []);
        }

        if (!empty($params)) {
            $sql = Helpers::buildUpsertSql(
                'pages', 
                ['url', 'title', 'hostname', 'platformId', 'account_id', 'data'], 
                ['title', 'platformId', 'data'], 
                'url', 
                count($pages)
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

        $params = [];
        foreach ($posts as $p) {
            $params[] = $p->platformId;
            $params[] = $p->pageId;
            $params[] = $p->accountId;
            $params[] = $p->channeledAccountId ?? null;
            $params[] = json_encode($p->data ?? []);
        }

        if (!empty($params)) {
            $sql = Helpers::buildUpsertSql(
                'posts', 
                ['postId', 'page_id', 'account_id', 'channeledAccount_id', 'data'], 
                ['data'], 
                ['postId', 'page_id', 'account_id', 'channeledAccount_id'], 
                count($posts)
            );
            $conn->executeStatement($sql, $params);
        }
    }
}
