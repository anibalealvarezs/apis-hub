<?php

namespace Repositories;

use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Enums\JobStatus;
use Faker\Factory;
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
        if (isset($data->status) && $data->status && $job = JobStatus::from($data->status)) {
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
     * @param int $id
     * @param bool $withAssociations
     * @return array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException
     */
    public function read(int $id, bool $withAssociations = true): ?array
    {
        $job = parent::read($id, false);
        $job['status'] = $this->getStatusName($job['status']);

        return $job;
    }

    /**
     * @param int $id
     * @param stdClass|null $data
     * @return array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException
     */
    public function update(int $id, stdClass $data = null): ?array
    {
        if (isset($data->status) && $data->status && $job = JobStatus::from($data->status)) {
            $data->status = $job->value;
        }

        return parent::update($id, $data);
    }
}
