<?php

namespace Repositories;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Creative;

/**
 * Repository for Creative entities, providing methods to query and manage creatives.
 */
class CreativeRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function findByCreativeId(string $creativeId): ?Creative
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.creativeId = :creativeId')
            ->setParameter('creativeId', $creativeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByName(string $name, int $limit = 100): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.name LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByDataAttribute(string $key, string $value, int $limit = 100): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.data->>:key = :value')
            ->setParameter('key', $key)
            ->setParameter('value', $value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findAdsUsingCreative(string $creativeId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('ca')
            ->from(ChanneledAd::class, 'ca')
            ->join('ca.creative', 'cr')
            ->where('cr.creativeId = :creativeId')
            ->setParameter('creativeId', $creativeId)
            ->getQuery()
            ->getResult();
    }

    public function findCampaignsUsingCreative(string $creativeId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('c')
            ->from(Campaign::class, 'c')
            ->join(ChanneledCampaign::class, 'cc', Join::WITH, 'cc.campaign = c')
            ->join(ChanneledAd::class, 'ca', Join::WITH, 'ca.channeledCampaign = cc')
            ->join('ca.creative', 'cr')
            ->where('cr.creativeId = :creativeId')
            ->setParameter('creativeId', $creativeId)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function findByPlatform(string $channel, int $limit = 100): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('cr')
            ->from(Creative::class, 'cr')
            ->join(ChanneledAd::class, 'ca', Join::WITH, 'ca.creative = cr')
            ->where('ca.channel = :channel')
            ->setParameter('channel', $channel)
            ->setMaxResults($limit)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}