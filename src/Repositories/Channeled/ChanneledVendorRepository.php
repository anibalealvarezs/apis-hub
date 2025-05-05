<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class ChanneledVendorRepository extends ChanneledBaseRepository
{
    /**
     * @param string $name
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByName(string $name, int $channel): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
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
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
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
            ->addSelect('p')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledProducts', 'p');
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
            $item['channeledProducts'] = array_map(function($channeledProduct) {
                unset($channeledProduct['channel']);
                return $channeledProduct;
            }, $item['channeledProducts']);
            return $item;
        }, $list));
    }
}
