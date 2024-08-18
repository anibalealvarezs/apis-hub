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
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     */
    public function read(int $id, bool $returnEntity = false, object $filters = null): Entity|array|null
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('c')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledCustomer', 'c')
            ->leftJoin('e.channeledProducts', 'p')
            ->leftJoin('e.channeledDiscounts', 'd')
            ->where('e.id = :id')
            ->setParameter('id', $id);
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        if ($returnEntity) {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        } else {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        }

        if (!$entity) {
            return null;
        }

        if (is_array($entity)) {
            $entity = $this->replaceChannelName($entity);
        }

        return $entity;
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
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
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
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function($item) {
            return $this->replaceChannelName($item);
        }, $list));
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
