<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class VendorRepository extends BaseRepository
{
    /**
     * @param string $name
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByName(string $name): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
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
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.name = :name')
            ->setParameter('name', $name)
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
            ->addSelect('v')
            ->addSelect('p')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledVendors', 'v');
        $query->leftJoin('v.channeledProducts', 'p');
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
            $item['channeledVendors'] = array_map(function($channelVendor) {
                $channelVendor['channel'] = Channels::from($channelVendor['channel'])->getName();
                $channelVendor['channeledProducts'] = array_map(function($channeledProduct) {
                    unset($channeledProduct['channel']);
                    return $channeledProduct;
                }, $channelVendor['channeledProducts']);
                return $channelVendor;
            }, $item['channeledVendors']);
            return $item;
        }, $list));
    }
}