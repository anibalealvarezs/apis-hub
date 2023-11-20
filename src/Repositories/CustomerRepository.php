<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Helpers\Helpers;
use ReflectionException;

class CustomerRepository extends BaseRepository
{
    /**
     * @param string $email
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByEmail(string $email): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
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
            ->from($this->_entityName, 'e');
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

        return new ArrayCollection($list);
    }
}