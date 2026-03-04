<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

/**
 * Repository for Page entities, providing methods to query and manage pages.
 */
class DeviceRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function getByType(string $type /*, bool $useCached = false */): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
