<?php

namespace Repositories\Channeled;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Entities\Analytics\Channel;
use Enums\QueryBuilderType;
use Exception;

class ChanneledOrderRepository extends ChanneledBaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::LAST => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
            QueryBuilderType::CUSTOM => null
        };

        return $query
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledCustomer', 'c')
            ->leftJoin('e.channeledProducts', 'p')
            ->leftJoin('e.channeledDiscounts', 'd');
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channel'] = Channel::from($entity['channel'])->getName();
        unset($entity['channeledCustomer']['channel']);
        $entity['channeledProducts'] = array_map(function ($channeledProduct) {
            $channeledProduct['channel'] = Channel::from($channeledProduct['channel'])->getName();
            return $channeledProduct;
        }, $entity['channeledProducts']);
        $entity['channeledDiscounts'] = array_map(function ($channeledDiscount) {
            $channeledDiscount['channel'] = Channel::from($channeledDiscount['channel'])->getName();
            return $channeledDiscount;
        }, $entity['channeledDiscounts']);
        return $entity;
    }
}
