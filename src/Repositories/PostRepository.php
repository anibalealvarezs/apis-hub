<?php

namespace Repositories;

use Doctrine\ORM\Query\Expr\Join;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Post;

/**
 * Repository for Post entities, providing methods to query and manage social media posts.
 */
class PostRepository extends BaseRepository
{
    public function findByPostId(string $postId): ?Post
    {
        return $this->createQueryBuilder('p')
            ->where('p.postId = :postId')
            ->setParameter('postId', $postId)
            ->getQuery()
            ->getOneOrNullResult();
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
            ->from(Post::class, 'p')
            ->join(Metric::class, 'm', Join::WITH, 'm.post = p')
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
            ->from(Post::class, 'p')
            ->join(Metric::class, 'm', Join::WITH, 'm.post = p')
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