<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\Channels;
use ReflectionEnum;
use ReflectionException;
use Repositories\BaseRepository;

class ChanneledBaseRepository extends BaseRepository
{
    /**
     * @param int $platformId
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByPlatformIdAndChannel(int $platformId, int $channel): ?Entity
    {
        if ((new ReflectionEnum(Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return parent::getByPlatformIdAndChannel(platformId: $platformId, channel: $channel);
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     * @throws MappingException
     * @throws ReflectionException
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e');
        foreach($filters as $key => $value) {
            if ($key == 'channel' && !is_int($value)) {
                $value = (new ReflectionEnum(Channels::class))->getConstant($value);
            }
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection($list);
    }

    /**
     * @param int $channel
     * @return string
     */
    public function getChannelName(int $channel): string
    {
        return Channels::from($channel)->getName();
    }
}