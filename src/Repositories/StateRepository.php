<?php

namespace Repositories;

use Doctrine\ORM\NonUniqueResultException;

class StateRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function findByNameAndCountry(string $name, int $countryId): ?object
    {
        return $this->createQueryBuilder('s')
            ->where('s.name = :name')
            ->andWhere('s.country = :countryId')
            ->setParameter('name', $name)
            ->setParameter('countryId', $countryId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCountry(int $countryId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.country = :countryId')
            ->setParameter('countryId', $countryId)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
