<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Helpers\Helpers;
use ReflectionException;
use stdClass;

class BaseRepository extends EntityRepository
{
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
     * @return Entity|array|null
     * @throws NonUniqueResultException
     */
    public function read(int $id, bool $returnEntity = false): Entity|array|null
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery();

        if ($returnEntity) {
            $entity = $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        } else {
            $entity = $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        }

        if (!$entity) {
            return null;
        }

        return $entity;
    }

    /**
     * @return int
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getCount(): int
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($this->_entityName, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e');
        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }
        foreach($filters as $key => $value) {
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection($list);
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
        $entity = $this->_em->find($this->_entityName, $id);

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
        $entity = $this->_em->find($this->_entityName, $id);

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

    /**
     * @param int $platformId
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByPlatformIdAndChannel(int $platformId, int $channel): ?Entity
    {
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
