<?php

declare(strict_types=1);

namespace Classes\Conversions;

use Doctrine\Common\Collections\ArrayCollection;

class FacebookOrganicConvert
{
    /**
     * @param array $posts
     * @param int|string|null $pageId Internal Page ID
     * @param int|string|null $accountId Internal Account ID
     * @param int|string|null $channeledAccountId
     * @return ArrayCollection
     */
    public static function posts(
        array $posts,
        int|string|null $pageId = null,
        int|string|null $accountId = null,
        int|string|null $channeledAccountId = null
    ): ArrayCollection {
        return new ArrayCollection(array_map(function ($post) use ($pageId, $accountId, $channeledAccountId) {
            return (object) [
                'platformId' => $post['id'] ?? null,
                'pageId' => $pageId,
                'accountId' => $accountId,
                'channeledAccountId' => $channeledAccountId,
                'data' => $post,
            ];
        }, $posts));
    }

    /**
     * @param array $pages
     * @param int|string|null $accountId Internal Account ID
     * @return ArrayCollection
     */
    public static function pages(array $pages, int|string|null $accountId = null): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($page) use ($accountId) {
            return (object) [
                'url' => $page['id'] ?? null,
                'title' => $page['name'] ?? $page['id'] ?? '',
                'hostname' => 'facebook.com',
                'platformId' => $page['id'] ?? null,
                'accountId' => $accountId,
                'data' => $page,
            ];
        }, $pages));
    }
}
