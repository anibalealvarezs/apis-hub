<?php

namespace Repositories;

use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Enums\JobStatus;
use Faker\Factory;
use Helpers\Helpers;
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
     * @param string $filename
     * @return array
     */
    public function getJobsByFilename(string $filename): array
    {
        $qb = $this->createQueryBuilder('j');
        $qb->where('j.filename = :filename')
            ->setParameter('filename', $filename);
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
     * @param stdClass|null $data
     * @return array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException
     */
    public function create(stdClass $data = null): ?array
    {
        if (isset($data->status) && is_int($data->status) && $job = JobStatus::from($data->status)) {
            $data->status = $job->value;
        } else {
            $data->status = JobStatus::processing->value;
        }

        if (!isset($data->filename)) {
            $data->filename = Factory::create()->uuid;
        }

        return parent::create($data);
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
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
            if ($key == 'status' && !is_int($value)) {
                $value = (new ReflectionEnum(JobStatus::class))->getConstant($value);
            }
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
     * @return array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function update(int $id, stdClass $data = null): ?array
    {
        if (!isset($data->status) || !$data->status) {
            return parent::update($id, $data);
        }

        if ($job = is_int($data->status) ? JobStatus::from($data->status) : (new ReflectionEnum(JobStatus::class))->getConstant($data->status)) {
            $data->status = $job->value;
        }

        return parent::update($id, $data);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     */
    protected function mapEntityData(object $entity, bool $withAssociations = false): array
    {
        $data = parent::mapEntityData($entity, $withAssociations);
        $data['status'] = $this->getStatusName($data['status']);

        return $data;
    }
}
