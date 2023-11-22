<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

class ChanneledDiscountRepository extends ChanneledBaseRepository
{
    /**
     * @param string $code
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByCodeAndChannel(string $code, int $channel): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
