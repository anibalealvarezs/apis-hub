<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ChanneledProductVariantRepository extends ChanneledBaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::LAST => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
        };

        return $query
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProduct', 'p')
            ->leftJoin('p.channeledVendor', 'v')
            ->leftJoin('p.channeledProductCategories', 'c');
    }

    /**
     * @param string $sku
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getBySku(string $sku, int $channel): ?Entity
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.sku = :sku')
            ->setParameter('sku', $sku)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(hydrationMode: AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $sku
     * @param int $channel
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsBySku(string $sku, int $channel): bool
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.sku = :sku')
                ->setParameter('sku', $sku)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channel'] = Channels::from($entity['channel'])->getName();
        unset($entity['channeledProduct']['channel']);
        unset($entity['channeledProduct']['channeledVendor']['channel']);
        $entity['channeledProduct']['channeledProductCategories'] = array_map(function($channeledProductCategory) {
            unset($channeledProductCategory['channel']);
            return $channeledProductCategory;
        }, $entity['channeledProduct']['channeledProductCategories']);
        return $entity;
    }
}