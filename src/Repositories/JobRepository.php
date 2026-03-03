<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Enums\AnalyticsEntity;
use Enums\Channel;
use Enums\JobStatus;
use Enums\QueryBuilderType;
use Faker\Factory;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
class JobRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws \Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new \Exception('To be implemented'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param object|null $data
     * @phpstan-param object{status?: int|string, entity?: string, channel?: string, uuid?: string}|null $data
     * @param bool $returnEntity
     * @return array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException|OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): ?array
    {
        $data = (array) ($data ?? []);

        if (isset($data['status']) && is_int($data['status']) && $job = JobStatus::tryFrom($data['status'])) {
            $data['status'] = $job->value;
        } else {
            $data['status'] = JobStatus::scheduled->value;
        }

        if (!isset($data['entity']) || !$data['entity']) {
            throw new InvalidArgumentException('Entity is required');
        }
        if (!(new ReflectionEnum(AnalyticsEntity::class))->getConstant($data['entity'])) {
            throw new InvalidArgumentException('Invalid entity');
        }

        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel is required');
        }
        if (!(new ReflectionEnum(Channel::class))->getConstant($data['channel'])) {
            throw new InvalidArgumentException('Invalid channel');
        }

        if (!isset($data['uuid'])) {
            $data['uuid'] = Factory::create()->uuid;
        }

        return parent::create((object) $data);
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
                if ($key === 'status' && !is_int($value)) {
                    $value = (new ReflectionEnum(objectOrClass: JobStatus::class))->getConstant($value);
                }
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
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result['status'] = $this->getStatusName($result['status']);
        return parent::processResult($result);
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
        return JobStatus::from($status)->getName();
    }

    /**
     * @param int $id
     * @param object|null $data
     * @phpstan-param object{status?: int|string}|null $data
     * @param bool $returnEntity
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function update(int $id, ?object $data = null, bool $returnEntity = false): ?array
    {
        $data = (array) ($data ?? []);
        if (!isset($data['status']) || !$data['status']) {
            return parent::update($id, (object) $data);
        }

        if ($job = is_int($data['status']) ? JobStatus::from($data['status']) : (new ReflectionEnum(JobStatus::class))->getConstant($data['status'])) {
            $data['status'] = $job->value;
        }

        return parent::update($id, (object) $data);
    }
}
