<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

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
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->andWhere('e.channel = :channelId')
            ->setParameter('channelId', $channel instanceof Channels ? $channel->value : $channel)
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
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->andWhere('e.channel = :channelId')
            ->setParameter('channelId', $channel instanceof Channels ? $channel->value : $channel)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
