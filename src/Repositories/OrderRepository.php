<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Enums\QueryBuilderType;

class OrderRepository extends BaseRepository
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
            QueryBuilderType::CUSTOM => null,
        };

        return $query->addSelect('o')
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledOrders', 'o')
            ->leftJoin('o.channeledCustomer', 'c')
            ->leftJoin('o.channeledProducts', 'p')
            ->leftJoin('o.channeledDiscounts', 'd');
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->replaceChannelName($result);
        return parent::processResult($result);
    }

    /**
     * @param string $orderId
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByOrderId(string $orderId): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $orderId
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByOrderId(string $orderId): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channeledOrders'] = array_map(function ($channeledOrder) {
            $channeledOrder['channel'] = Channel::from($channeledOrder['channel'])->getName();
            unset($channeledOrder['channeledCustomer']['channel']);
            $channeledOrder['channeledProducts'] = array_map(function ($channeledProduct) {
                unset($channeledProduct['channel']);
                return $channeledProduct;
            }, $channeledOrder['channeledProducts']);
            $channeledOrder['channeledDiscounts'] = array_map(function ($channeledDiscount) {
                unset($channeledDiscount['channel']);
                return $channeledDiscount;
            }, $channeledOrder['channeledDiscounts']);
            return $channeledOrder;
        }, $entity['channeledOrders']);

        return $entity;
    }
}
