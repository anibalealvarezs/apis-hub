<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\AnalyticsEntities;
use Enums\Channels;
use Enums\JobStatus;
use Faker\Factory;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
use stdClass;

class JobRepository extends BaseRepository
{
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
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     */
    public function read(int $id, bool $returnEntity = false, object $filters = null): Entity|array|null
    {
        $entity = parent::read($id, $returnEntity);

        if (!$returnEntity && $entity) {
            $entity['status'] = $this->getStatusName($entity['status']);
        }

        return $entity;
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
        if (isset($data->status) && is_int($data->status) && $job = JobStatus::from($data->status)) {
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
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e');
        foreach($filters as $key => $value) {
            if ($key == 'status' && !is_int($value)) {
                $value = (new ReflectionEnum(JobStatus::class))->getConstant($value);
            }
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function ($job) {
            $job['status'] = $this->getStatusName($job['status']);
            return $job;
        }, $list));
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

        if ($job = is_int($data->status) ? JobStatus::from($data->status) : (new ReflectionEnum(JobStatus::class))->getConstant($data->status)) {
            $data->status = $job->value;
        }

        return parent::update($id, $data);
    }
}
