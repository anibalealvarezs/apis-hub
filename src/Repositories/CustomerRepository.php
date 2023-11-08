<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Analytics\Customer;
use Enums\Channels;
use ReflectionEnum;

class CustomerRepository extends BaseRepository
{
    /**
     * @param int $platformId
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getCustomerByPlatformAndChannel(int $platformId, int $channel): ?Customer
    {
        if ((new ReflectionEnum(Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }
}
