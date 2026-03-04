<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

/**
 * Repository for Page entities, providing methods to query and manage pages.
 */
class CountryRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code /*, bool $useCached = false */): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
