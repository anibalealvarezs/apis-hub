<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

class ChanneledCustomerRepository extends ChanneledBaseRepository
{
    /**
     * @param string $email
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByEmailAndChannel(string $email, int $channel): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
