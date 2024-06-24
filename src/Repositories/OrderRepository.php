<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class OrderRepository extends BaseRepository
{
    /**
     * @param string $orderId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByOrderId(string $orderId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
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
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('o')
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledOrders', 'o');
        $query->leftJoin('o.channeledCustomer', 'c');
        $query->leftJoin('o.channeledProducts', 'p');
        $query->leftJoin('o.channeledDiscounts', 'd');
        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }
        foreach($filters as $key => $value) {
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function($item) {
            $item['channeledOrders'] = array_map(function($channeledOrder) {
                $channeledOrder['channel'] = Channels::from($channeledOrder['channel'])->getName();
                unset($channeledOrder['channeledCustomer']['channel']);
                $channeledOrder['channeledProducts'] = array_map(function($channeledProduct) {
                    unset($channeledProduct['channel']);
                    return $channeledProduct;
                }, $channeledOrder['channeledProducts']);
                $channeledOrder['channeledDiscounts'] = array_map(function($channeledDiscount) {
                    unset($channeledDiscount['channel']);
                    return $channeledDiscount;
                }, $channeledOrder['channeledDiscounts']);
                return $channeledOrder;
            }, $item['channeledOrders']);
            return $item;
        }, $list));
    }
}