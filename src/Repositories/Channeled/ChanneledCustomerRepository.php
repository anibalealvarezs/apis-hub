<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ChanneledCustomerRepository extends ChanneledBaseRepository
{
    /**
     * @param string $email
     * @param Channels|int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByEmail(string $email, Channels|int $channel /*, bool $useCached = false */): ?Entity
    {
        $channelValue = $channel instanceof Channels ? $channel->value : $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->andWhere('e.channel = :channelId')
            ->setParameter('channelId', $channelValue)
            ->setMaxResults(1) // Special condition because NetSuite could actually have multiple customers with the same email
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $email
     * @param Channels|int $channel
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsByEmail(string $email, Channels|int $channel): bool
    {
        $channelValue = $channel instanceof Channels ? $channel->value : $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->andWhere('e.channel = :channelId')
            ->setParameter('channelId', $channelValue)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
