<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Enums\Channels;
use Enums\QueryBuilderType;
use ReflectionException;
use Entities\Entity;
use stdClass;

class CustomerRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
        };

        return $query->addSelect('c')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledCustomers', 'c');
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
        if (!$data || !isset($data->email)) {
            return null; // Or throw new \InvalidArgumentException('Email is required')
        }
        return parent::create(data: $data, returnEntity: $returnEntity);
    }

    /**
     * @param string $email
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByEmail(string $email /*, bool $useCached = false */): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $email
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByEmail(string $email): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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
        $entity['channeledCustomers'] = array_map(function($channelCustomer) {
            $channelCustomer['channel'] = Channels::from($channelCustomer['channel'])->getName();
            return $channelCustomer;
        }, $entity['channeledCustomers']);
        return $entity;
    }
}