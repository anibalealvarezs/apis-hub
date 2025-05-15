<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class VendorRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
        };

        return $query->addSelect('v')
            ->addSelect('p')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledVendors', 'v')
            ->leftJoin('v.channeledProducts', 'p');
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        return $this->replaceChannelName($result);
    }

    /**
     * @param string $name
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByName(string $name): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $name
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByName(string $name): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function replaceChannelName(array $entity)
    {
        $entity['channeledVendors'] = array_map(function($channelVendor) {
            $channelVendor['channel'] = Channels::from($channelVendor['channel'])->getName();
            $channelVendor['channeledProducts'] = array_map(function($channeledProduct) {
                unset($channeledProduct['channel']);
                return $channeledProduct;
            }, $channelVendor['channeledProducts']);
            return $channelVendor;
        }, $entity['channeledVendors']);
        return $entity;
    }
}