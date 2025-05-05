<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;

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
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
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
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
