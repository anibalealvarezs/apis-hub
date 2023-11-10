<?php

namespace Repositories;

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
     * @return array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function create(stdClass $data = null): ?array
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

        return $this->read(id: $entity->getId());
    }

    /**
     * @param int $id
     * @param bool $withAssociations
     * @return array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function read(int $id, bool $withAssociations = false): ?array
    {
        $entity = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);

        if (!$entity) {
            return null;
        }

        return $this->mapEntityData($entity, $withAssociations);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     */
    protected function mapEntityData(object $entity, bool $withAssociations = false): array
    {
        $fields = array_keys($this->_class->fieldMappings);
        $associated = $this->_class->associationMappings;

        $data = [];
        foreach ($fields as $field) {
            if (method_exists($this->_entityName, 'get' . Helpers::toCamelcase($field))) {
                $data[$field] = $entity->{'get' . Helpers::toCamelcase($field)}();
            }
        }
        if (!$withAssociations) {
            return $data;
        }
        foreach ($associated as $association) {
            $fieldName = $association['fieldName'];
            $className = $association['targetEntity'];
            if (!method_exists($this->_entityName, 'get' . Helpers::toCamelcase($fieldName))) {
                continue;
            }
            $element = $entity->{'get' . Helpers::toCamelcase($fieldName)}();
            if (!$element) {
                continue;
            }
            if (Helpers::isEntity($this->_em, $element)) {
                $data[$fieldName] = Helpers::jsonSerialize($element);
                continue;
            }
            $data[$fieldName] = [];
            foreach ($element as $el) {
                $data[$fieldName][] = $this->_em->getRepository($className)->read($el->getId());
            }
        }

        return $data;
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
     * @param object|null $filters
     * @param bool $withAssociations
     * @return array
     * @throws MappingException
     * @throws ReflectionException
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, object $filters = null, bool $withAssociations = false): array
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e');
        foreach($filters as $key => $value) {
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult();

        return array_map(function ($element) use ($withAssociations) {
            return $this->mapEntityData($element, $withAssociations);
        }, $list);
    }

    /**
     * @param int $id
     * @param stdClass|null $data
     * @return bool|array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function update(int $id, stdClass $data = null): bool|array|null
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

        return $this->read($entity->getId());
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
