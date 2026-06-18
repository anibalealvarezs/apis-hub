<?php

namespace Repositories;

class LocationRepository extends BaseRepository
{
    public function findByPlatformId(string $platformId): ?object
    {
        return $this->createQueryBuilder('l')
            ->where('l.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCity(int $cityId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.city = :cityId')
            ->setParameter('cityId', $cityId)
            ->getQuery()
            ->getResult();
    }

    public function findByState(int $stateId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.state = :stateId')
            ->setParameter('stateId', $stateId)
            ->getQuery()
            ->getResult();
    }

    public function findByCountry(int $countryId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.country = :countryId')
            ->setParameter('countryId', $countryId)
            ->getQuery()
            ->getResult();
    }

    public function findByChanneledAccount(int $channeledAccountId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.channeledAccount = :channeledAccountId')
            ->setParameter('channeledAccountId', $channeledAccountId)
            ->getQuery()
            ->getResult();
    }
}
