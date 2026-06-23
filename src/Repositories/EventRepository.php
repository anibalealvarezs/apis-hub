<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

/**
 * Repository for Event entities, providing methods to query and manage events.
 */
class EventRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function getByName(string $name): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
