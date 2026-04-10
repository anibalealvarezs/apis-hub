<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Account;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Enums\QueryBuilderType;
use Exception;

class ChanneledAccountRepository extends ChanneledBaseRepository
{
    /**
     * @param string $name
     * @param int $channel
     * @param Account|string $type
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByNameAndType(string $name, int $channel, Account|string $type): ?Entity
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->andWhere('e.type = :type')
            ->setParameter('type', is_string($type) ? $type : $type->value)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $name
     * @param int $channel
     * @param Account|string $type
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsByNameAndType(string $name, int $channel, Account|string $type): bool
    {
        $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.name = :name')
                ->setParameter('name', $name)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->andWhere('e.type = :type')
                ->setParameter('type', is_string($type) ? $type : $type->value)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }
}
