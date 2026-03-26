<?php

namespace Repositories;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Job;
use Enums\AnalyticsEntity;
use Enums\Channel;
use Enums\JobStatus;
use Enums\QueryBuilderType;
use Faker\Factory;
use InvalidArgumentException;
use Helpers\Helpers;

class JobRepository extends BaseRepository
{
    public function __construct(\Doctrine\ORM\EntityManagerInterface $em, \Doctrine\ORM\Mapping\ClassMetadata $class)
    {
        parent::__construct($em, $class);
    }
    
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
     * @phpstan-param object{status?: int|string, entity?: string, channel?: string, uuid?: string, payload?: array}|null $data
     * @param bool $returnEntity
     * @return array|null
     * @throws NonUniqueResultException
     * @throws \ReflectionException
     * @throws MappingException|OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): ?array
    {
        $data = (array) ($data ?? []);

        $status = $data['status'] ?? null;
        if (is_numeric($status)) {
            $statusEnum = JobStatus::tryFrom((int)$status);
            $data['status'] = $statusEnum ? $statusEnum->value : JobStatus::scheduled->value;
        } elseif (is_string($status)) {
            $matched = false;
            foreach (JobStatus::cases() as $case) {
                if (strtolower($case->name) === strtolower($status)) {
                    $data['status'] = $case->value;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $data['status'] = JobStatus::scheduled->value;
            }
        } else {
            $data['status'] = JobStatus::scheduled->value;
        }

        if (!isset($data['entity']) || !$data['entity']) {
            throw new InvalidArgumentException('Entity is required');
        }
        if (!AnalyticsEntity::tryFrom($data['entity'])) {
            throw new InvalidArgumentException('Invalid entity');
        }

        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel is required');
        }
        if ($chanEnum = Channel::tryFromName($data['channel'])) {
            $data['channel'] = $chanEnum->name;
        } else {
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
        ?string $endDate = null,
        ?array $extra = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder();
        $isGlobal = false;

        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'global' && $value) {
                    $isGlobal = true;
                    continue;
                }
                if ($key === 'status') {
                    if (is_numeric($value)) {
                        $value = (int) $value;
                    } elseif (is_string($value)) {
                        // Try to get value from Enum name
                        foreach (JobStatus::cases() as $case) {
                            if (strtolower($case->name) === strtolower($value)) {
                                $value = $case->value;
                                break;
                            }
                        }
                    }
                }
                if ($key === 'channel') {
                    if ($chanEnum = Channel::tryFromName($value)) {
                        $value = $chanEnum->name;
                    }
                }
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        // Apply Smart Context (localized filters) if not global and not explicitly overridden
        if (!$isGlobal) {
            $envChannel = getenv('API_SOURCE');
            $envEntity = getenv('API_ENTITY');
            $envStart = getenv('START_DATE');
            $envEnd = getenv('END_DATE');

            if ($envChannel && (!is_object($filters) || !isset($filters->channel))) {
                if ($chanEnum = Channel::tryFromName($envChannel)) {
                    $envChannel = $chanEnum->name;
                }
                $query->andWhere('e.channel = :ctx_channel')->setParameter('ctx_channel', $envChannel);
            }
            if ($envEntity && (!is_object($filters) || !isset($filters->entity))) {
                $equivalents = [$envEntity];
                if (strpos($envEntity, 'channeled_') === 0) {
                    $equivalents[] = str_replace('channeled_', '', $envEntity);
                } else {
                    $equivalents[] = 'channeled_' . $envEntity;
                }
                $query->andWhere('e.entity IN (:ctx_entities)')->setParameter('ctx_entities', array_unique($equivalents));
            }

            // Differentiate by Date Range in payload (e.g. gsc-jan vs gsc-feb)
            // We use a loose LIKE pattern to be compatible with MySQL JSON columns.
            // In PostgreSQL, this is handled via Native SQL in the calling methods to avoid DQL parsing issues.
            if (!Helpers::isPostgres()) {
                $payloadField = 'e.payload';
                if ($envStart && (!is_object($filters) || !isset($filters->startDate))) {
                    $query->andWhere("({$payloadField} LIKE :ctx_start_pattern1 OR {$payloadField} LIKE :ctx_start_pattern2)")
                        ->setParameter('ctx_start_pattern1', '%startDate%' . $envStart . '%')
                        ->setParameter('ctx_start_pattern2', '%start_date%' . $envStart . '%');
                }
                if ($envEnd && (!is_object($filters) || !isset($filters->endDate))) {
                    $query->andWhere("({$payloadField} LIKE :ctx_end_pattern1 OR {$payloadField} LIKE :ctx_end_pattern2)")
                        ->setParameter('ctx_end_pattern1', '%endDate%' . $envEnd . '%')
                        ->setParameter('ctx_end_pattern2', '%end_date%' . $envEnd . '%');
                }
            }
        }

        // Apply technical date filters (createdAt) ONLY if explicitly requested via params/CLI
        // We bypass the automatic env-to-createdAt mapping because Jobs are technical records.
        if ($startDate || $endDate) {
            $this->applyDateFilters($query, $startDate, $endDate);
        }

        $query->orderBy("e.$orderBy", strtoupper($orderDir))
            ->setMaxResults($limit)
            ->setFirstResult($limit * $pagination);

        return $query;
    }

    public function countElements(
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        $isGlobal = false;

        if ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'global' && $value) {
                    $isGlobal = true;
                    continue;
                }
                if ($key === 'status') {
                    if (is_numeric($value)) {
                        $value = (int) $value;
                    } elseif (is_string($value)) {
                        foreach (JobStatus::cases() as $case) {
                            if (strtolower($case->name) === strtolower($value)) {
                                $value = $case->value;
                                break;
                            }
                        }
                    }
                }
                if ($key === 'channel') {
                    if ($chanEnum = Channel::tryFromName($value)) {
                        $value = $chanEnum->name;
                    }
                }
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        if (!$isGlobal) {
            $envChannel = getenv('API_SOURCE');
            if ($envChannel && (!is_object($filters) || !isset($filters->channel))) {
                if ($chanEnum = Channel::tryFromName($envChannel)) {
                    $envChannel = $chanEnum->name;
                }
                $query->andWhere('e.channel = :ctx_channel')->setParameter('ctx_channel', $envChannel);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return (int) $query->getQuery()->getSingleScalarResult();
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
        return $this->readMultiple()->toArray();
    }

    /**
     * @param int $status
     * @param string|null $channel
     * @param string|null $instanceName
     * @return Job[]
     */
    public function getJobsByStatus(int $status, ?string $channel = null, ?string $instanceName = null): array
    {
        if (Helpers::isPostgres()) {
            $sql = "SELECT * FROM jobs WHERE status = :status";
            $params = ['status' => $status];
            
            if ($channel) {
                $sql .= " AND channel = :channel";
                $params['channel'] = $channel;
            }
            
            if ($instanceName) {
                $sql .= " AND CAST(payload AS text) LIKE :instance_name_pattern";
                $params['instance_name_pattern'] = '%instance_name%' . $instanceName . '%';
            }
            
            $sql .= " ORDER BY id ASC LIMIT 100";
            
            $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->_em);
            $rsm->addRootEntityFromClassMetadata($this->getEntityName(), 'j');
            
            $query = $this->_em->createNativeQuery($sql, $rsm);
            $query->setParameters($params);
            
            return $query->getResult();
        }

        $filters = ['status' => $status];
        if ($channel) {
            if ($chanEnum = \Enums\Channel::tryFromName($channel)) {
                $channel = $chanEnum->name;
            }
            $filters['channel'] = $channel;
        }

        $qb = $this->buildReadMultipleQuery(
            ids: null,
            filters: (object)$filters,
            orderBy: 'id',
            orderDir: 'ASC',
            limit: 100,
            pagination: 0
        );

        if ($instanceName) {
            $payloadField = 'e.payload';
            $qb->andWhere("{$payloadField} LIKE :instance_name_pattern")
               ->setParameter('instance_name_pattern', '%instance_name%' . $instanceName . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param string $uuid
     * @return array
     */
    public function getJobsByUuid(string $uuid): array
    {
        $list = $this->readMultiple(filters: (object)['uuid' => $uuid])->toArray();
        return count($list) > 0 ? $list[0] : [];
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

        $statusValue = $data['status'];
        $mappedStatus = null;

        if (is_numeric($statusValue)) {
            $mappedStatus = JobStatus::tryFrom((int)$statusValue);
        } elseif (is_string($statusValue)) {
            foreach (JobStatus::cases() as $case) {
                if (strtolower($case->name) === strtolower($statusValue)) {
                    $mappedStatus = $case;
                    break;
                }
            }
        }

        if ($mappedStatus) {
            $data['status'] = $mappedStatus->value;
        }

        return parent::update($id, (object) $data) ?: null;
    }

    /**
     * Atomically claims a job by moving it from 'scheduled' to 'processing'.
     * Returns true if the claim was successful.
     *
     * @param int $id
     * @return bool
     */
    public function claimJob(int $id): bool
    {
        $qb = $this->_em->createQueryBuilder();
        $updatedRows = $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':processing')
            ->set('e.updatedAt', ':now')
            ->where('e.id = :id')
            ->andWhere($qb->expr()->in('e.status', ':claimable'))
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('now', new \DateTime())
            ->setParameter('id', $id)
            ->setParameter('claimable', [JobStatus::scheduled->value, JobStatus::delayed->value])
            ->getQuery()
            ->execute();

        return (int)$updatedRows > 0;
    }

    /**
     * Resets a job to scheduled status.
     *
     * @param int $id
     * @return bool
     */
    public function resetJob(int $id): bool
    {
        $qb = $this->_em->createQueryBuilder();
        $updatedRows = $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':scheduled')
            ->set('e.updatedAt', ':now')
            ->where('e.id = :id')
            ->setParameter('scheduled', JobStatus::scheduled->value)
            ->setParameter('now', new \DateTime())
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();

        return (int)$updatedRows > 0;
    }

    /**
     * Marks a job as delayed (e.g. for rate limiting).
     *
     * @param int $id
     * @param string|null $message
     * @return bool
     */
    public function markAsDelayed(int $id, ?string $message = null): bool
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':delayed')
            ->set('e.updatedAt', ':now');
        
        if ($message) {
            $qb->set('e.message', ':message')
               ->setParameter('message', $message);
        }

        $updatedRows = $qb->where('e.id = :id')
            ->setParameter('delayed', JobStatus::delayed->value)
            ->setParameter('now', new \DateTime())
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();

        return (int)$updatedRows > 0;
    }

    /**
     * @param string $instanceName
     * @param int $withinHours
     * @return bool
     */
    public function hasSuccessfulRecentJob(string $instanceName, int $withinHours = 24): bool
    {
        if (Helpers::isPostgres()) {
            $since = new \DateTime();
            $since->modify("-$withinHours hours");

            $sql = "SELECT count(j.id) FROM jobs j WHERE CAST(j.payload AS text) LIKE :instance_name_pattern AND j.status = :completed AND j.updated_at >= :since";
            
            $stmt = $this->_em->getConnection()->prepare($sql);
            $result = $stmt->executeQuery([
                'instance_name_pattern' => '%instance_name%' . $instanceName . '%',
                'completed' => JobStatus::completed->value,
                'since' => $since->format('Y-m-d H:i:s')
            ]);
            
            return (int)$result->fetchOne() > 0;
        }

        $qb = $this->_em->createQueryBuilder();
        $since = new \DateTime();
        $since->modify("-$withinHours hours");

        $payloadField = 'e.payload';

        $count = $qb->select('count(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where("{$payloadField} LIKE :instance_name_pattern")
            ->andWhere('e.status = :completed')
            ->andWhere('e.updatedAt >= :since')
            ->setParameter('instance_name_pattern', '%instance_name%' . $instanceName . '%')
            ->setParameter('completed', JobStatus::completed->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$count > 0;
    }

    /**
     * @param string $instanceName
     * @return \DateTime|null
     */
    public function getLastSuccessfulJobTime(string $instanceName): ?\DateTime
    {
        if (Helpers::isPostgres()) {
            $sql = "SELECT j.updated_at FROM jobs j WHERE CAST(j.payload AS text) LIKE :instance_name_pattern AND j.status = :completed ORDER BY j.updated_at DESC LIMIT 1";
            
            $stmt = $this->_em->getConnection()->prepare($sql);
            $result = $stmt->executeQuery([
                'instance_name_pattern' => '%instance_name%' . $instanceName . '%',
                'completed' => JobStatus::completed->value
            ]);
            
            $val = $result->fetchOne();
            return $val ? new \DateTime($val) : null;
        }

        $qb = $this->_em->createQueryBuilder();
        $payloadField = 'e.payload';

        $job = $qb->select('e')
            ->from($this->getEntityName(), 'e')
            ->where("{$payloadField} LIKE :instance_name_pattern")
            ->andWhere('e.status = :completed')
            ->setParameter('instance_name_pattern', '%instance_name%' . $instanceName . '%')
            ->setParameter('completed', JobStatus::completed->value)
            ->orderBy('e.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $job ? $job->getUpdatedAt() : null;
    }

    /**
     * @param string $instanceName
     * @param int|null $excludeJobId
     * @return bool
     */
    public function isAnotherJobProcessing(string $instanceName, ?int $excludeJobId = null): bool
    {
        if (Helpers::isPostgres()) {
            $sql = "SELECT count(j.id) FROM jobs j WHERE CAST(j.payload AS text) LIKE :instance_name_pattern AND j.status = :processing";
            $params = [
                'instance_name_pattern' => '%instance_name%' . $instanceName . '%',
                'processing' => JobStatus::processing->value,
            ];
            
            if ($excludeJobId) {
                $sql .= " AND j.id != :excludeId";
                $params['excludeId'] = $excludeJobId;
            }
            
            $stmt = $this->_em->getConnection()->prepare($sql);
            $result = $stmt->executeQuery($params);
            
            return (int)$result->fetchOne() > 0;
        }

        $qb = $this->_em->createQueryBuilder();
        $payloadField = 'e.payload';

        $qb->select('count(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where("{$payloadField} LIKE :instance_name_pattern")
            ->andWhere('e.status = :processing')
            ->setParameter('instance_name_pattern', '%instance_name%' . $instanceName . '%')
            ->setParameter('processing', JobStatus::processing->value);

        if ($excludeJobId) {
            $qb->andWhere('e.id != :excludeId')
               ->setParameter('excludeId', $excludeJobId);
        }

        return (int)$qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @param int $hours
     * @return int
     */
    public function cleanupStuckJobs(int $hours = 6): int
    {
        $since = new \DateTime();
        $since->modify("-$hours hours");

        $qb = $this->_em->createQueryBuilder();
        return $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':failed')
            ->set('e.message', ':message')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.updatedAt <= :since')
            ->setParameter('failed', JobStatus::failed->value)
            ->setParameter('message', "Job timed out after $hours hours")
            ->setParameter('now', new \DateTime())
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->execute();
    }
}
