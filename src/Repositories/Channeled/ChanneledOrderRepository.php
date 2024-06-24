<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class ChanneledOrderRepository extends ChanneledBaseRepository
{
    /**
     * @param string $orderId
     * @param Channels $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByOrderId(string $orderId, Channels $channel): ?Entity
    {
        return parent::getByPlatformIdAndChannel($orderId, $channel->value);
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
        return parent::existsByPlatformIdAndChannel($orderId, $channel->value);
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
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledCustomer', 'c');
        $query->leftJoin('e.channeledProducts', 'p');
        $query->leftJoin('e.channeledDiscounts', 'd');
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
            $item['channel'] = Channels::from($item['channel'])->getName();
            unset($item['channeledCustomer']['channel']);
            $item['channeledProducts'] = array_map(function($channeledProduct) {
                $channeledProduct['channel'] = Channels::from($channeledProduct['channel'])->getName();
                return $channeledProduct;
            }, $item['channeledProducts']);
            $item['channeledDiscounts'] = array_map(function($channeledDiscount) {
                $channeledDiscount['channel'] = Channels::from($channeledDiscount['channel'])->getName();
                return $channeledDiscount;
            }, $item['channeledDiscounts']);
            return $item;
        }, $list));
    }
}
