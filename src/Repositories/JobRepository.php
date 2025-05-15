<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\AnalyticsEntities;
use Enums\Channels;
use Enums\JobStatus;
use Enums\QueryBuilderType;
use Faker\Factory;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
use stdClass;

class JobRepository extends BaseRepository
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

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param stdClass|null $data
     * @param bool $returnEntity
     * @return array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException
     */
    public function create(stdClass $data = null, bool $returnEntity = false): ?array
    {
        if (isset($data->status) && is_int($data->status) && $job = QueryBuilderType::from($data->status)) {
            $data->status = $job->value;
        } else {
            $data->status = JobStatus::scheduled->value;
        }

        if (!isset($data->entity) || !$data->entity) {
            throw new InvalidArgumentException('Entity is required');
        }
        if (!(new ReflectionEnum(AnalyticsEntities::class))->getConstant($data->entity)) {
            throw new InvalidArgumentException('Invalid entity');
        }

        if (!isset($data->channel)) {
            throw new InvalidArgumentException('Channel is required');
        }
        if (!(new ReflectionEnum(Channels::class))->getConstant($data->channel)) {
            throw new InvalidArgumentException('Invalid channel');
        }

        if (!isset($data->uuid)) {
            $data->uuid = Factory::create()->uuid;
        }

        return parent::create($data);
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
                if ($key === 'status' && !is_int($value)) {
                    $value = (new ReflectionEnum(objectOrClass: QueryBuilderType::class))->getConstant($value);
                }
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
        $result['status'] = $this->getStatusName($result['status']);
        return $result;
    }

    /**
     * @return array
     */
    public function getJobs(): array
    {
        $qb = $this->createQueryBuilder('j');
        $jobs = $qb->getQuery()->getResult();
        foreach ($jobs as $key => $job) {
            $jobs[$key]['status'] = $this->getStatusName($job['status']);
        }

        return $jobs;
    }

    /**
     * @param int $status
     * @return array
     */
    public function getJobsByStatus(int $status): array
    {
        $qb = $this->createQueryBuilder('j');
        $qb->where('j.status = :status')
            ->setParameter('status', $status);
        $job = $qb->getQuery()->getResult();
        $job['status'] = $this->getStatusName($job['status']);

        return $job;
    }

    /**
     * @param string $uuid
     * @return array
     */
    public function getJobsByUuid(string $uuid): array
    {
        $qb = $this->createQueryBuilder('j');
        $qb->where('j.uuid = :uuid')
            ->setParameter('uuid', $uuid);
        $job = $qb->getQuery()->getResult();
        $job['status'] = $this->getStatusName($job['status']);

        return $job;
    }

    /**
     * @param int $status
     * @return string
     */
    public function getStatusName(int $status): string
    {
        return QueryBuilderType::from($status)->getName();
    }

    /**
     * @param int $id
     * @param stdClass|null $data
     * @param bool $returnEntity
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function update(int $id, stdClass $data = null, bool $returnEntity = false): ?array
    {
        if (!isset($data->status) || !$data->status) {
            return parent::update($id, $data);
        }

        if ($job = is_int($data->status) ? QueryBuilderType::from($data->status) : (new ReflectionEnum(QueryBuilderType::class))->getConstant($data->status)) {
            $data->status = $job->value;
        }

        return parent::update($id, $data);
    }
}
