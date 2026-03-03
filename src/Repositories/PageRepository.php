<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Repository for Page entities, providing methods to query and manage pages.
 */
class PageRepository extends BaseRepository
{
    public function getByUrl(string $url): ?Page
    {
        $normalizedUrl = rtrim($url, '/');
        $logger = new Logger('gsc');
        $logger->pushHandler(new StreamHandler('logs/gsc.log', Level::Info));
        $logger->info("getByUrl: url=$url, normalized=$normalizedUrl");

        $qb = $this->createQueryBuilder('p')
            ->where('LOWER(p.url) = LOWER(:url)')
            ->setParameter('url', $normalizedUrl);

        $page = $qb->getQuery()->getOneOrNullResult();

        // Debug existing URLs
        $allUrls = $this->createQueryBuilder('p')
            ->select('p.id, p.url')
            ->getQuery()
            ->getArrayResult();
        $logger->info("All page URLs in database: " . json_encode($allUrls));

        return $page;
    }

    public function getByTitle(string $title, int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.title LIKE :title')
            ->setParameter('title', '%' . $title . '%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function getByPlatformId(string $platformId): ?Page
    {
        return $this->createQueryBuilder('p')
            ->where('p.platformId LIKE :platformId')
            ->setParameter('platformId', $platformId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    public function findByDataAttribute(string $key, string $value, int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.data->>:key = :value')
            ->setParameter('key', $key)
            ->setParameter('value', $value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByCampaign(string $campaignId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(Page::class, 'p')
            ->join(Metric::class, 'm', Join::WITH, 'm.page = p')
            ->join(ChanneledCampaign::class, 'cc', Join::WITH, 'm.channeledCampaign = cc')
            ->join(Campaign::class, 'c', Join::WITH, 'cc.campaign = c')
            ->where('c.campaignId = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function findByMetricValue(string $metricName, float $value, string $operator = '>', int $limit = 100): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(Page::class, 'p')
            ->join(Metric::class, 'm', Join::WITH, 'm.page = p')
            ->where('m.name = :metricName')
            ->andWhere("m.value $operator :value")
            ->setParameter('metricName', $metricName)
            ->setParameter('value', $value)
            ->setMaxResults($limit)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}