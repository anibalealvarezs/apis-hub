<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;
use ReflectionEnum;
use Repositories\BaseRepository;

class ChanneledBaseRepository extends BaseRepository
{
    /**
     * @param int|string $platformId
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByPlatformId(int|string $platformId, int $channel): ?Entity
    {
        if ((new ReflectionEnum(objectOrClass: Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(hydrationMode: AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param int|string $platformId
     * @param int $channel
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByPlatformId(int|string $platformId, int $channel): bool
    {
        if ((new ReflectionEnum(objectOrClass: Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     */
    public function read(int $id, bool $returnEntity = false, object $filters = null): Entity|array|null
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id);
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        if ($returnEntity) {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        } else {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        }

        if (!$entity) {
            return null;
        }

        if (is_array($entity)) {
            $entity['channel'] = Channels::from($entity['channel'])->getName();
        }

        return $entity;
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e');
        if ($filters) {
            foreach($filters as $key => $value) {
                if ($key == 'channel' && !is_int($value)) {
                    $value = (new ReflectionEnum(objectOrClass: Channels::class))->getConstant($value);
                }
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function($item) {
            $item['channel'] = Channels::from($item['channel'])->getName();
            return $item;
        }, $list));
    }

    /**
     * @param int $channel
     * @return string
     */
    public function getChannelName(int $channel): string
    {
        return Channels::from($channel)->getName();
    }

    /**
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformId(int $channel): ?array
    {
        return $this->_em->createQueryBuilder()
            ->select('e, LENGTH(e.platformId) AS HIDDEN length')
            ->from($this->getEntityName(), 'e')
            ->where('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->addOrderBy('length', 'DESC')
            ->addOrderBy('e.platformId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
    }

    /**
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformCreatedAt(int $channel): ?array
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->addOrderBy('e.platformCreatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
    }
}