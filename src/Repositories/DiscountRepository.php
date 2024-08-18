<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class DiscountRepository extends BaseRepository
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
            ->addSelect('d')
            ->addSelect('pr')
            // ->addSelect('o')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledDiscounts', 'd')
            ->leftJoin('d.channeledPriceRule', 'pr')
            // ->leftJoin('d.channeledOrders', 'o')
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
     * @param string $code
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $code
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByCode(string $code): bool
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
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
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('d')
            ->addSelect('pr')
            // ->addSelect('o')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledDiscounts', 'd');
        $query->leftJoin('d.channeledPriceRule', 'pr');
        // $query->leftJoin('d.channeledOrders', 'o');
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
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channeledDiscounts'] = array_map(function ($channelDiscount) {
            $channelDiscount['channel'] = Channels::from($channelDiscount['channel'])->getName();
            unset($channelDiscount['channeledPriceRule']['channel']);
            /* $channelDiscount['channeledOrders'] = array_map(function ($channeledOrder) {
                unset($channeledOrder['channel']);
                return $channeledOrder;
            }, $channelDiscount['channeledOrders']); */
            return $channelDiscount;
        }, $entity['channeledDiscounts']);
        return $entity;
    }
}