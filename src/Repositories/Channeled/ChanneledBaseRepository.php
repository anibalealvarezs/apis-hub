<?php

namespace Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Entities\Analytics\Channel as ChannelEntity;
use Enums\QueryBuilderType;
use Exception;
use InvalidArgumentException;
use Repositories\BaseRepository;
use RuntimeException;
use ValueError;

class ChanneledBaseRepository extends BaseRepository
{
    private static array $channelCache = [];

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
            QueryBuilderType::CUSTOM => null,
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
        $channelValue = $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channelValue)
            ->setMaxResults(1)
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
        $channelValue = $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.platformId = :platformId')
                ->setParameter('platformId', $platformId)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channelValue)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param object|null $filters
     * @return int
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function countElements(
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int {
        if ($filters && property_exists($filters, 'channel') && !is_int($filters->channel)) {
            try {
                $this->validateChannelName($filters->channel);
            } catch (ValueError) {
                throw new InvalidArgumentException("Invalid channel name: " . $filters->channel);
            }
        }
        $query = $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT);
        if ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'channel' && !is_int($value)) {
                    $value = $this->validateChannelName($value); // Already validated, but kept for consistency
                }
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->replaceChannelName($result);
        return parent::processResult($result);
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        if (isset($entity['channel']) && is_int($entity['channel'])) {
            $channel = $this->resolveChannel($entity['channel']);
            $entity['channel'] = $channel ? $channel->getName() : (string)$entity['channel'];
        }
        return $entity;
    }

    /**
     * @param int|string|null $channel
     * @return int
     */
    protected function validateChannel(int|string|null $channel): int
    {
        if ($channel === null || (is_string($channel) && empty($channel))) {
            throw new InvalidArgumentException('Invalid channel: channel cannot be null or empty');
        }
        
        $channelEntity = $this->resolveChannel($channel);
        if (!$channelEntity) {
            throw new InvalidArgumentException('Invalid channel: ' . $channel);
        }
        
        return $channelEntity->getId();
    }

    /**
     * @param string $name
     * @return int
     */
    protected function validateChannelName(string $name): int
    {
        return $this->validateChannel($name);
    }

    /**
     * @param int|string $identity
     * @return ChannelEntity|null
     */
    protected function resolveChannel(int|string $identity): ?ChannelEntity
    {
        if (isset(self::$channelCache[$identity])) {
            return self::$channelCache[$identity];
        }

        $repo = $this->_em->getRepository(ChannelEntity::class);
        if (is_int($identity) || ctype_digit($identity)) {
            $channel = $repo->find((int)$identity);
        } else {
            $channel = $repo->findOneBy(['name' => $identity]);
        }

        if ($channel) {
            self::$channelCache[$identity] = $channel;
            self::$channelCache[$channel->getId()] = $channel;
            self::$channelCache[$channel->getName()] = $channel;
        }

        return $channel;
    }

    /**
     * @param int|string $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformId(int|string $channel): ?array
    {
        $channelVal = $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::LAST)
            ->where('e.channel = :channel')
            ->setParameter('channel', $channelVal)
            ->addOrderBy('length', 'DESC')
            ->addOrderBy('e.platformId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
    }

    /**
     * @param int|string $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformCreatedAt(int|string $channel): ?array
    {
        $channelVal = $this->validateChannel($channel);
        $data = $this->createBaseQueryBuilderNoJoins(QueryBuilderType::LAST)
            ->where('e.channel = :channel')
            ->setParameter('channel', $channelVal)
            ->addOrderBy('e.platformCreatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        return $data ? ['platformCreatedAt' => $data['platformCreatedAt']] : null;
    }
}
