<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;
use Exception;
use InvalidArgumentException;
use Repositories\BaseRepository;
use ValueError;

class ChanneledBaseRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        return $this->createBaseQueryBuilderNoJoins($type);
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilderNoJoins(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::LAST => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param int|string $platformId
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     * @throws InvalidArgumentException
     */
    public function getByPlatformId(int|string $platformId, int $channel): ?Entity
    {
        $this->validateChannel($channel);

        return $this->createBaseQueryBuilder()
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
     * @throws InvalidArgumentException
     */
    public function existsByPlatformId(int|string $platformId, int $channel): bool
    {
        $this->validateChannel($channel);

        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.platformId = :platformId')
                ->setParameter('platformId', $platformId)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param object|null $filters
     * @return int
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function countElements(object $filters = null): int
    {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        if ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'channel' && !is_int($value)) {
                    try {
                        $value = $this->validateChannelName($value);
                    } catch (ValueError $e) {
                        throw new InvalidArgumentException("Invalid channel name: $value");
                    }
                }
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }
        return $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        return $this->replaceChannelName($result);
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channel'] = Channels::from($entity['channel'])->getName();
        return $entity;
    }

    /**
     * @param int $channel
     * @return int
     */
    protected function validateChannel(int $channel): int
    {
        $channelEnum = Channels::tryFrom($channel);
        if ($channelEnum === null) {
            throw new InvalidArgumentException('Invalid channel');
        }
        return $channelEnum->value;
    }

    /**
     * @param string $name
     * @return int
     * @throws ValueError
     */
    protected function validateChannelName(string $name): int
    {
        try {
            $channel = Channels::tryFromName($name);
            if ($channel === null) {
                throw new InvalidArgumentException("Invalid channel name: $name");
            }
            return $channel->value;
        } catch (Exception) {
            throw new InvalidArgumentException("Invalid channel name: $name");
        }
    }

    /**
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformId(int $channel): ?array
    {
        $this->validateChannel($channel);
        $data = $this->createBaseQueryBuilder(QueryBuilderType::LAST)
            ->where('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->addOrderBy('length', 'DESC')
            ->addOrderBy('e.platformId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        return $data ? $this->replaceChannelName($data) : null;
    }

    /**
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformCreatedAt(int $channel): ?array
    {
        $this->validateChannel($channel);
        $data = $this->createBaseQueryBuilder()
            ->where('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->addOrderBy('e.platformCreatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        return $data ? $this->replaceChannelName($data) : null;
    }
}