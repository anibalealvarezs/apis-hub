<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

class OrderRepository extends BaseRepository
{
    /**
     * @param string $orderId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByOrderId(string $orderId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }


}