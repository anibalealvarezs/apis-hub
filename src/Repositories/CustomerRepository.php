<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class CustomerRepository extends BaseRepository
{
    /**
     * @param string $email
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByEmail(string $email /*, bool $useCached = false */): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);

        /* if (!$useCached) {
            return $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        }

        $cache = new FilesystemAdapter(namespace: 'my_cache_namespace');
        $cacheItem = $cache->getItem(key: 'getByEmail_' . md5($email));

        if (!$cacheItem->isHit()) {
            $result = $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
            $cacheItem->set($result);
            $cache->save($cacheItem);
        }

        return $cacheItem->get(); */
    }

    /**
     * @param string $email
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByEmail(string $email): bool
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
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
            ->addSelect('c')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledCustomers', 'c');
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
            $item['channeledCustomers'] = array_map(function($channelCustomer) {
                $channelCustomer['channel'] = Channels::from($channelCustomer['channel'])->getName();
                return $channelCustomer;
            }, $item['channeledCustomers']);
            return $item;
        }, $list));
    }
}