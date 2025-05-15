<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\QueryBuilderType;

class ChanneledDiscountRepository extends ChanneledBaseRepository
{
    /**
     * @param string $code
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code, int $channel): ?Entity
    {
        $channel = $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $code
     * @param int $channel
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByCode(string $code, int $channel): bool
    {
        $channel = $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
