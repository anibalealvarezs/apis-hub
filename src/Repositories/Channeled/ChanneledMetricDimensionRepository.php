<?php

namespace Repositories\Channeled;

use DateTime;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledMetricDimension;
use Entities\Entity;
use Enums\QueryBuilderType;
use Exception;
use InvalidArgumentException;
use Repositories\BaseRepository;

class ChanneledMetricDimensionRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        return $query->addSelect('c')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledMetric', 'd');
    }


    /**
     * Create a new ChanneledMetricDimension from data.
     *
     * @param object{dimensionKey: string, dimensionValue: string, channeledMetric: ChanneledMetric}|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws InvalidArgumentException
     * @throws ORMException
     */
    public function create(?object $data = null, bool $returnEntity = false): Entity|array|null
    {
        $data = (array) ($data ?? []);
        if (!isset($data['dimensionKey']) || !isset($data['dimensionValue']) || !isset($data['channeledMetric'])) {
            throw new InvalidArgumentException("dimensionKey, dimensionValue, and channeledMetric are required");
        }

        $entity = new ChanneledMetricDimension();

        // Explicitly set properties
        $entity->addDimensionKey($data['dimensionKey'])
            ->addDimensionValue($data['dimensionValue'])
            ->addChanneledMetric($data['channeledMetric']);

        // Fallback for createdAt/updatedAt
        $now = new DateTime('now');
        $entity->addCreatedAt($now);
        $entity->addUpdatedAt($now);

        // Ensure channeledMetric is managed
        if (!$this->_em->contains($data['channeledMetric'])) {
            $this->_em->persist($data['channeledMetric']);
        }

        $this->_em->persist($entity);
        $this->_em->flush();

        // Skip read for returnEntity: true to avoid transaction visibility issues
        return $returnEntity ? $entity : $this->read($entity->getId());
    }

    /**
     * @param string $key
     * @param string $value
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws Exception
     */
    public function existsByKeyAndValue(string $key, string $value): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.dimensionKey = :dimensionKey')
                ->andWhere('e.dimensionValue = :dimensionValue')
                ->setParameters([
                    'dimensionKey' => $key,
                    'dimensionValue' => $value,
                ])
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param string $key
     * @param string $value
     * @param ChanneledMetric $channeledMetric
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws Exception
     */
    public function existsByKeyValueAndMetric(string $key, string $value, ChanneledMetric $channeledMetric): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.dimensionKey = :dimensionKey')
                ->andWhere('e.dimensionValue = :dimensionValue')
                ->andWhere('e.channeledMetric = :channeledMetric')
                ->setParameters([
                    'dimensionKey' => $key,
                    'dimensionValue' => $value,
                    'channeledMetric' => $channeledMetric,
                ])
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param string $key
     * @param string $value
     * @return array
     * @throws Exception
     */
    public function findByKeyAndValue(string $key, string $value): array
    {
        return $this->createBaseQueryBuilder()
            ->where('e.dimensionKey = :dimensionKey')
            ->andWhere('e.dimensionValue = :dimensionValue')
            ->setParameters([
                'dimensionKey' => $key,
                'dimensionValue' => $value,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string $dimensionKey
     * @param string $dimensionValue
     * @param ChanneledMetric $channeledMetric
     * @return ChanneledMetricDimension|null
     * @throws NonUniqueResultException
     */
    public function findOneByKeyValueAndMetric(string $dimensionKey, string $dimensionValue, ChanneledMetric $channeledMetric): ?ChanneledMetricDimension
    {
        return $this->createQueryBuilder('d')
            ->where('d.dimensionKey = :dimensionKey')
            ->andWhere('d.dimensionValue = :dimensionValue')
            ->andWhere('d.channeledMetric = :channeledMetric')
            ->setParameter('dimensionKey', $dimensionKey)
            ->setParameter('dimensionValue', $dimensionValue)
            ->setParameter('channeledMetric', $channeledMetric)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param ChanneledMetric $channeledMetric
     * @return array
     * @throws Exception
     */
    public function findByChanneledMetric(ChanneledMetric $channeledMetric): array
    {
        return $this->createBaseQueryBuilder()
            ->where('e.channeledMetric = :channeledMetric')
            ->setParameter('channeledMetric', $channeledMetric)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string $key
     * @param int $channel
     * @return array
     * @throws Exception
     */
    public function findDistinctValues(string $key, int $channel): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('DISTINCT e.dimensionValue')
            ->join('e.channeledMetric', 'd')
            ->where('e.dimensionKey = :dimensionKey')
            ->andWhere('d.channel = :channel')
            ->setParameters([
                'dimensionKey' => $key,
                'channel' => $channel,
            ])
            ->getQuery()
            ->getScalarResult();

        return array_column($results, 'dimensionValue');
    }
}
