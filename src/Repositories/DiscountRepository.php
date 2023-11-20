<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

class DiscountRepository extends BaseRepository
{
    /**
     * @param string $code
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}