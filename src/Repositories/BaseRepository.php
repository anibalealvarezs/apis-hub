<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\QueryBuilderType;
use Helpers\Helpers;
use ReflectionException;
use stdClass;

class BaseRepository extends EntityRepository
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
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param stdClass|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function create(stdClass $data = null, bool $returnEntity = false): Entity|array|null
    {
        $entityName = $this->getEntityName();
        $entity = new $entityName();

        if ((array) $data) {
            foreach ($data as $key => $value) {
                if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                    $entity->{'add' . Helpers::toCamelcase($key)}($value);
                }
            }
        }

        $this->_em->persist($entity);
        $this->_em->flush();

        return $this->read(
            id: $entity->getId(),
            returnEntity: $returnEntity,
        );
    }

    /**
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
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
     * @return QueryBuilder
     */
    protected function buildReadQuery(int $id, ?object $filters = null): QueryBuilder
    {
        $query = $this->createBaseQueryBuilder()
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

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
     * @return int
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function countElements(object $filters = null): int
    {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }
        return $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @param string $orderBy
     * @param string $orderDir
     * @return ArrayCollection
     */
    public function readMultiple(
        int $limit = 100,
        int $pagination = 0,
        ?array $ids = null,
        ?object $filters = null,
        string $orderBy = 'id',
        string $orderDir = 'DESC'
    ): ArrayCollection
    {
        $query = $this->buildReadMultipleQuery(
            ids: $ids,
            filters: $filters,
            orderBy: $orderBy,
            orderDir: $orderDir,
            limit: $limit,
            pagination: $pagination
        );

        $list = $query->getQuery()->getResult(AbstractQuery::HYDRATE_ARRAY);

        $processedList = array_map(
            fn($item) => $this->processResult($item),
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
     * @return QueryBuilder
     */
    protected function buildReadMultipleQuery(
        ?array $ids,
        ?object $filters,
        string $orderBy,
        string $orderDir,
        int $limit,
        int $pagination
    ): QueryBuilder
    {
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

        $query->orderBy("e.$orderBy", strtoupper($orderDir))
            ->setMaxResults($limit)
            ->setFirstResult($limit * $pagination);

        return $query;
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        return $result; // Default: no processing
    }

    /**
     * @param int $id
     * @param stdClass|null $data
     * @param bool $returnEntity
     * @return bool|array|Entity|null
     * @throws NonUniqueResultException
     */
    public function update(int $id, stdClass $data = null, bool $returnEntity = false): bool|array|null|Entity
    {
        $entity = $this->_em->find($this->getEntityName(), $id);

        if (!$entity) {
            return false;
        }

        if ((array) $data) {
            foreach ($data as $key => $value) {
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
