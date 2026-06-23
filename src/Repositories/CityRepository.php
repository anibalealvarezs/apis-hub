<?php

namespace Repositories;

use Doctrine\ORM\NonUniqueResultException;

class CityRepository extends BaseRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function findByNameAndCountry(string $name, int $countryId): ?object
    {
        return $this->createQueryBuilder('c')
            ->where('c.name = :name')
            ->andWhere('c.country = :countryId')
            ->setParameter('name', $name)
            ->setParameter('countryId', $countryId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByState(int $stateId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.state = :stateId')
            ->setParameter('stateId', $stateId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCountry(int $countryId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.country = :countryId')
            ->setParameter('countryId', $countryId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
