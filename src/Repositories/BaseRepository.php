<?php

namespace Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\Mapping\MappingException;
use Helpers\Helpers;
use ReflectionException;
use stdClass;

class BaseRepository extends EntityRepository
{
    /**
     * @param EntityManagerInterface $em
     * @param ClassMetadata $class
     */
    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        parent::__construct($em, $class);
        $this->entityName = $this->getEntityName();
        $this->em         = $this->getEntityManager();
        $this->class      = $this->getClassMetadata();
    }

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
                if (isset($this->class->associationMappings[$key]) && isset($this->class->associationMappings[$key]['joinTable'])) {
                    if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                        $entity->{'add' . Helpers::toCamelcase($key)}($value);
                    }
                } else {
                    if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                        $entity->{'add' . Helpers::toCamelcase($key)}($value);
                    }
                }
            }
        }
    
        $this->em->persist($entity);
        $this->em->flush();

        return $this->read($entity->getId());
    }

    /**
     * @param int $id
     * @param bool $withAssociations
     * @return array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function read(int $id, bool $withAssociations = true): ?array
    {
        $entity = $this->em->createQueryBuilder()
            ->select('e')
            ->from($this->entityName, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$entity) {
            return null;
        }

        $fields = array_keys($this->class->fieldMappings);
        $associated = $this->class->associationMappings;

        $data = [];
        foreach ($fields as $field) {
            if (method_exists($entity, 'get' . Helpers::toCamelcase($field))) {
                $data[$field] = $entity->{'get' . Helpers::toCamelcase($field)}();
            }
        }
        foreach ($associated as $association) {
            $fieldName = $association['fieldName'];
            $className = $association['targetEntity'];
            if (method_exists($entity, 'get' . Helpers::toCamelcase($fieldName))) {
                $element = $entity->{'get' . Helpers::toCamelcase($fieldName)}();
                if (!$element) {
                    continue;
                }
                if (Helpers::isEntity($this->em, $element)) {
                    $data[$fieldName] = Helpers::jsonSerialize($element);
                    continue;
                }
                if (!$withAssociations) {
                    continue;
                }
                $data[$fieldName] = [];
                foreach ($element as $el) {
                    $data[$fieldName][] = $this->em->getRepository($className)->read($el->getId(), false);
                }
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
        return $this->em->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($this->entityName, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function readMultiple(int $limit = 10, int $pagination = 0, object $filters = null)
    {
        return $this->em->createQueryBuilder()
                ->select('e')
                ->from($this->entityName, 'e')
                ->setMaxResults($limit)
                ->setFirstResult($limit * $pagination)
                ->getQuery()
                ->getResult();
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
        $entity = $this->em->find($this->entityName, $id);

        if (!$entity) {
            return false;
        }

        if ((array) $data) {
            foreach ($data as $key => $value) {
                if (isset($this->class->associationMappings[$key]) && isset($this->class->associationMappings[$key]['joinTable'])) {
                    if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                        $entity->{'add' . Helpers::toCamelcase($key)}($value);
                    }
                } else {
                    if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                        $entity->{'add' . Helpers::toCamelcase($key)}($value);
                    }
                }
            }
        }
    
        $this->em->persist($entity);
        $this->em->flush();

        return $this->read($entity->getId());
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $entity = $this->em->find($this->entityName, $id);

        if (!$entity) {
            return false;
        }

        $props = $this->class->fieldMappings;

        foreach ($props as $key => $value) {
            if (is_a($entity->{'get' . Helpers::toCamelcase($key)}(), 'Collection')) {
                $entity->{'remove' . Helpers::toCamelcase($key)}($entity->{'get' . Helpers::toCamelcase($key)}());
            }
        }

        $this->em->remove($entity);
        $this->em->flush();

        return true;
    }
}
