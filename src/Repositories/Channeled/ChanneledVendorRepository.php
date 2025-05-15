<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ChanneledVendorRepository extends ChanneledBaseRepository
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
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProducts', 'p');
    }

    /**
     * @param string $name
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByName(string $name, int $channel): ?Entity
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $name
     * @param int $channel
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByName(string $name, int $channel): bool
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.name = :name')
                ->setParameter('name', $name)
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
        $entity['channeledProducts'] = array_map(function($channeledProduct) {
            unset($channeledProduct['channel']);
            return $channeledProduct;
        }, $entity['channeledProducts']);
        return $entity;
    }
}