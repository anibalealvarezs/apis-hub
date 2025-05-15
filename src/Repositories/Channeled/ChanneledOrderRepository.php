<?php

namespace Repositories\Channeled;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ChanneledOrderRepository extends ChanneledBaseRepository
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
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledCustomer', 'c')
            ->leftJoin('e.channeledProducts', 'p')
            ->leftJoin('e.channeledDiscounts', 'd');
    }

    /**
     * @param string $orderId
     * @param Channels $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByOrderId(string $orderId, Channels $channel): ?Entity
    {
        return parent::getByPlatformId($orderId, $channel->value);
    }

    /**
     * @param string $orderId
     * @param Channels $channel
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsByOrderId(string $orderId, Channels $channel): bool
    {
        return parent::existsByPlatformId($orderId, $channel->value);
    }

    /**
     * @param mixed $entity
     * @return mixed
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channel'] = Channels::from($entity['channel'])->getName();
        unset($entity['channeledCustomer']['channel']);
        $entity['channeledProducts'] = array_map(function($channeledProduct) {
            $channeledProduct['channel'] = Channels::from($channeledProduct['channel'])->getName();
            return $channeledProduct;
        }, $entity['channeledProducts']);
        $entity['channeledDiscounts'] = array_map(function($channeledDiscount) {
            $channeledDiscount['channel'] = Channels::from($channeledDiscount['channel'])->getName();
            return $channeledDiscount;
        }, $entity['channeledDiscounts']);
        return $entity;
    }
}
