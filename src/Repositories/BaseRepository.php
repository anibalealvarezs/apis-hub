<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\QueryBuilderType;
use Exception;
use Helpers\Helpers;
use ReflectionException;

class BaseRepository extends EntityRepository
{
    /**
     * List of top-level result fields to strip before returning the response.
     * Set via setHideFields() from the controller layer.
     */
    private array $hideFields = [];

    /**
     * Set the list of fields to hide from the result.
     *
     * @param string[] $fields
     * @return static
     */
    public function setHideFields(array $fields): static
    {
        $this->hideFields = $fields;
        return $this;
    }

    /**
     * Remove any fields listed in $this->hideFields from the top level of a result array.
     *
     * @param array $result
     * @return array
     */
    protected function applyHideFields(array $result): array
    {
        foreach ($this->hideFields as $field) {
            unset($result[trim($field)]);
        }
        return $result;
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        return $this->createBaseQueryBuilderNoJoins($type);
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilderNoJoins(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::AGGREGATE => $query, // Select will be set by buildAggregateQuery
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param array $aggregations map of "alias" => "FUNCTION(field)"
     * @param array $groupBy list of fields to group by
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     * @throws Exception
     */
    public function aggregate(
        array $aggregations,
        array $groupBy = [],
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $connection = $this->_em->getConnection();
        $qb = $connection->createQueryBuilder();
        $tableName = $this->_class->getTableName();
        $qb->from($tableName, 'e');

        // Specialized logic for ChanneledMetric to support deep joins in aggregation
        $isChanneledMetric = str_ends_with($this->getEntityName(), 'ChanneledMetric');
        if ($isChanneledMetric) {
            $qb->join('e', 'metrics', 'm', 'e.metric_id = m.id')
               ->join('m', 'metric_configs', 'mc', 'm.metricConfig_id = mc.id');
        }

        // Selects with aggregation functions
        foreach ($aggregations as $alias => $expr) {
            $parsedExpr = $this->mapFieldToSql($expr, true);
            $qb->addSelect("$parsedExpr AS $alias");
        }

        // Grouping and dimension handling
        foreach ($groupBy as $field) {
            if ($isChanneledMetric && str_starts_with($field, 'dimensions.')) {
                $dimKey = substr($field, 11);
                $dimAlias = "dim_" . preg_replace('/[^a-z0-9]/', '_', $dimKey);
                $qb->leftJoin('e', 'channeled_metric_dimensions', $dimAlias, "e.id = $dimAlias.channeledMetric_id AND $dimAlias.dimensionKey = :key_$dimAlias")
                   ->setParameter("key_$dimAlias", $dimKey)
                   ->addSelect("$dimAlias.dimensionValue AS " . ($field === 'dimensions.page' ? 'page' : $dimAlias))
                   ->addGroupBy("$dimAlias.dimensionValue");
            } else {
                $sqlField = $this->mapFieldToSql($field);
                $qb->addSelect("$sqlField AS $field")->addGroupBy($sqlField);
            }
        }

        // Apply filters
        if ($filters) {
            foreach ($filters as $key => $value) {
                $sqlKey = $this->mapFieldToSql($key);
                $paramName = 'f_' . preg_replace('/[^a-z0-9]/', '_', $key);
                $qb->andWhere("$sqlKey = :$paramName")
                   ->setParameter($paramName, $value);
            }
        }

        // Apply date filters using the correctly mapped column names
        if ($startDate || $endDate) {
            $dateField = 'platformCreatedAt'; // Default for channeled entities
            if (!$this->_class->hasField($dateField)) {
                $dateField = $this->_class->hasField('createdAt') ? 'createdAt' : 'date';
            }
            $sqlDateField = $this->mapFieldToSql($dateField);

            if ($startDate) {
                $qb->andWhere("$sqlDateField >= :startDate")
                   ->setParameter('startDate', $startDate);
            }
            if ($endDate) {
                $qb->andWhere("$sqlDateField <= :endDate")
                   ->setParameter('endDate', $endDate);
            }
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Maps a framework field (e.g. metadata.clicks) to a SQL expression.
     */
    protected function mapFieldToSql(string $expr, bool $isAggregate = false): string
    {
        $field = trim($expr);

        // If it's an aggregate expression, it might contain functions, arithmetic and multiple fields.
        if ($isAggregate) {
            // Find all potential field references and map them while leaving functions and operators intact.
            $patterns = [
                '/metadata\.[a-zA-Z0-9_]+/',
                '/data\.[a-zA-Z0-9_]+/',
                '/metric\.[a-zA-Z0-9_]+/',
                '/metricConfig\.[a-zA-Z0-9_]+/',
                '/\b(name|period|metricDate|value|platformCreatedAt|createdAt|date)\b/'
            ];

            return preg_replace_callback($patterns, function ($matches) {
                return $this->mapFieldToSql($matches[0], false);
            }, $field);
        }

        // JSON extraction (metadata.field or data.field)
        if (str_starts_with($field, 'metadata.') || str_starts_with($field, 'data.')) {
            $isData = str_starts_with($field, 'data.');
            $path = substr($field, $isData ? 5 : 9);
            $source = $isData ? 'e.data' : 'e.metadata';
            
            if (!$isData && str_ends_with($this->getEntityName(), 'ChanneledMetric')) {
                $source = 'm.metadata';
            }
            return "JSON_UNQUOTE(JSON_EXTRACT($source, '$.$path'))";
        }

        // Relation mapping for metrics
        if (str_starts_with($field, 'metric.')) {
            return "m." . substr($field, 7);
        }
        if (str_starts_with($field, 'metricConfig.')) {
            return "mc." . substr($field, 13);
        }

        // Common aliasing for metricDate and name
        if ($field === 'metricDate' || $field === 'name' || $field === 'period') {
            return "mc.$field";
        }
        if ($field === 'value') {
            return "m.value";
        }

        return "e.$field";
    }

    /**
     * @param array $aggregations
     * @param array $groupBy
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildAggregateQuery(
        array $aggregations,
        array $groupBy = [],
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::AGGREGATE);

        // Build SELECT with aggregations
        foreach ($aggregations as $alias => $funcExpr) {
            // Basic parsing for simple DQL functions (SUM, AVG, etc)
            // Note: For complex JSON fields, we might need Native SQL or custom DQL functions.
            $query->addSelect("$funcExpr AS $alias");
        }

        // Build GROUP BY
        foreach ($groupBy as $field) {
            $query->addSelect("e.$field")->addGroupBy("e.$field");
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query;
    }

    /**
     * @param object|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): Entity|array|null
    {
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $entityName = $this->getEntityName();
                $entity = new $entityName();

                if ((array) $data) {
                    foreach ((array) $data as $key => $value) {
                        if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                            $entity->{'add' . Helpers::toCamelcase($key, true)}($value);
                        }
                    }
                }

                $this->getEntityManager()->persist($entity);
                $this->getEntityManager()->flush();

                return $this->read(
                    id: $entity->getId(),
                    returnEntity: $returnEntity,
                );
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount); // Backoff: 100ms, 200ms, 300ms
                    continue;
                }
                error_log("BaseRepository::create failed after $maxRetries retries: {$e->getMessage()}");
                throw $e;
            }
        }
        return null;
    }

    /**
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     * @throws Exception
     */
    public function read(int $id, bool $returnEntity = false, ?object $filters = null): Entity|array|null
    {
        $query = $this->buildReadQuery(id: $id, filters: $filters);

        $entity = $returnEntity
            ? $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT)
            : $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if (!$entity) {
            return null;
        }

        if (!is_array($entity)) {
            return $entity;
        }

        return $this->processResult(result: $entity);
    }

    /**
     * @param int $id
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildReadQuery(
        int $id,
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder()
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query;
    }

    /**
     * @return int
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getCount(): int
    {
        return $this->createBaseQueryBuilder(QueryBuilderType::COUNT)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return int
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function countElements(
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @param string $orderBy
     * @param string $orderDir
     * @param string|null $startDate
     * @param string|null $endDate
     * @return ArrayCollection
     * @throws Exception
     */
    public function readMultiple(
        int $limit = 100,
        int $pagination = 0,
        ?array $ids = null,
        ?object $filters = null,
        string $orderBy = 'id',
        string $orderDir = 'DESC',
        ?string $startDate = null,
        ?string $endDate = null
    ): ArrayCollection {
        // Fallback for repositories without ID fetch capability if needed, but not here
        $idQueryBuilder = $this->buildReadMultipleQuery(
            ids: $ids,
            filters: $filters,
            orderBy: $orderBy,
            orderDir: $orderDir,
            limit: $limit,
            pagination: $pagination,
            startDate: $startDate,
            endDate: $endDate
        );

        // First step: Get ONLY the IDs we need, respecting limit and pagination
        $idResult = $idQueryBuilder->select('DISTINCT e.id AS id')->getQuery()->getScalarResult();
        $targetIds = array_column($idResult, 'id');

        if (empty($targetIds)) {
            return new ArrayCollection([]);
        }

        // Second step: Fetch full data for only these IDs, with all joins
        $dataQueryBuilder = $this->createBaseQueryBuilder()
            ->where('e.id IN (:targetIds)')
            ->setParameter('targetIds', $targetIds)
            ->orderBy("e.$orderBy", strtoupper($orderDir));

        $list = $dataQueryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_ARRAY);

        $processedList = array_map(
            fn ($item) => $this->processResult($item),
            $list
        );

        return new ArrayCollection($processedList);
    }

    /**
     * @param array|null $ids
     * @param object|null $filters
     * @param string $orderBy
     * @param string $orderDir
     * @param int $limit
     * @param int $pagination
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildReadMultipleQuery(
        ?array $ids,
        ?object $filters,
        string $orderBy,
        string $orderDir,
        int $limit,
        int $pagination,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder();

        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        $query->orderBy("e.$orderBy", strtoupper($orderDir))
            ->setMaxResults($limit)
            ->setFirstResult($limit * $pagination);

        return $query;
    }

    /**
     * Apply date range filters if appropriate fields exist in the entity.
     *
     * @param QueryBuilder $query
     * @param string|null $startDate
     * @param string|null $endDate
     */
    protected function applyDateFilters(QueryBuilder $query, ?string $startDate, ?string $endDate): void
    {
        if (!$startDate && !$endDate) {
            return;
        }

        $dateField = null;
        if ($this->_class->hasField('platformCreatedAt')) {
            $dateField = 'platformCreatedAt';
        } elseif ($this->_class->hasField('createdAt')) {
            $dateField = 'createdAt';
        } elseif ($this->_class->hasField('date')) {
            $dateField = 'date';
        }

        if ($dateField) {
            if ($startDate) {
                $query->andWhere("e.$dateField >= :startDate")
                    ->setParameter('startDate', $startDate);
            }
            if ($endDate) {
                $query->andWhere("e.$dateField <= :endDate")
                    ->setParameter('endDate', $endDate);
            }
        }
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->formatDates($result);
        return $this->applyHideFields($result);
    }

    /**
     * Recursive function to format all DateTimeInterface objects in an array.
     *
     * @param array $data
     * @param string $format
     * @return array
     */
    protected function formatDates(array $data, string $format = \DateTimeInterface::ATOM): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format($format);
            } elseif (is_array($value)) {
                $data[$key] = $this->formatDates($value, $format);
            }
        }
        return $data;
    }

    /**
     * @param int $id
     * @param object|null $data
     * @param bool $returnEntity
     * @return bool|array|Entity|null
     * @throws NonUniqueResultException
     */
    public function update(int $id, ?object $data = null, bool $returnEntity = false): bool|array|null|Entity
    {
        $entity = $this->_em->find($this->getEntityName(), $id);

        if (!$entity) {
            return false;
        }

        if ((array) $data) {
            foreach ((array) $data as $key => $value) {
                if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                    $entity->{'add' . Helpers::toCamelcase($key)}($value);
                }
            }
        }

        $entity->onPreUpdate();

        $this->_em->persist($entity);
        $this->_em->flush();

        return $this->read(
            id: $entity->getId(),
            returnEntity: $returnEntity,
        );
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $entity = $this->_em->find($this->getEntityName(), $id);

        if (!$entity) {
            return false;
        }

        $props = $this->_class->fieldMappings;

        foreach ($props as $key => $value) {
            if (is_a($entity->{'get' . Helpers::toCamelcase($key)}(), 'Collection')) {
                $entity->{'remove' . Helpers::toCamelcase($key)}($entity->{'get' . Helpers::toCamelcase($key)}());
            }
        }

        $this->_em->remove($entity);
        $this->_em->flush();

        return true;
    }
}
