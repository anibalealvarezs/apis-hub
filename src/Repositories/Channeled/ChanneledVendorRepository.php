<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;

class ChanneledVendorRepository extends ChanneledBaseRepository
{
    /**
     * @param string $name
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByNameAndChannel(string $name, int $channel): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
