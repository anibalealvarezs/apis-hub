<?php

declare(strict_types=1);

namespace Classes;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;

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
            $sql = 'INSERT INTO pages (url, title, hostname, platformId, account_id, data) VALUES '
                . implode(', ', array_fill(0, count($pages), '(?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE title = VALUES(title), platformId = VALUES(platformId), data = VALUES(data)';
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
            $sql = 'INSERT INTO posts (postId, page_id, account_id, channeledAccount_id, data) VALUES '
                . implode(', ', array_fill(0, count($posts), '(?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE data = VALUES(data)';
            $conn->executeStatement($sql, $params);
        }
    }
}
