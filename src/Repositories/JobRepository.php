<?php

namespace Repositories;

use DateTime;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Channel;
use Entities\Entity;
use Entities\Job;
use Enums\AnalyticsEntity;
use Enums\JobStatus;
use Enums\QueryBuilderType;
use Exceptions\ConfigurationException;
use Faker\Factory;
use Helpers\Helpers;
use InvalidArgumentException;
use ReflectionException;
use Services\CacheService;
use Throwable;

class JobRepository extends BaseRepository
{
    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
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
            QueryBuilderType::CUSTOM, QueryBuilderType::AGGREGATE => throw new \Exception('To be implemented'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param object|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws MappingException|OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): Entity|array|null
    {
        if (is_object($data)) {
            $data = (array)$data;
        }

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
            if (! $matched) {
                $data['status'] = JobStatus::scheduled->value;
            }
        } else {
            $data['status'] = JobStatus::scheduled->value;
        }

        if (! isset($data['entity']) || ! $data['entity']) {
            throw new InvalidArgumentException('Entity is required');
        }
        if (! AnalyticsEntity::tryFrom($data['entity'])) {
            throw new InvalidArgumentException('Invalid entity: '.$data['entity']);
        }

        if (! isset($data['channel'])) {
            throw new InvalidArgumentException('Channel is required');
        }
        if ($chanEnum = Channel::tryFromName($data['channel'])) {
            $data['channel'] = $chanEnum->getName();
        } else {
            throw new InvalidArgumentException('Invalid channel');
        }

        if (! isset($data['uuid'])) {
            $data['uuid'] = Factory::create()->uuid;
        }

        return parent::create((object)$data, $returnEntity);
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
     * @param array|null $extra
     * @return QueryBuilder
     * @throws Exception
     * @throws \Exception
     */
    protected function buildReadMultipleQuery(
        ?array  $ids,
        ?object $filters,
        string  $orderBy,
        string  $orderDir,
        int     $limit,
        int     $pagination,
        ?string $startDate = null,
        ?string $endDate = null,
        ?array  $extra = null
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
                        $value = (int)$value;
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
                        $value = $chanEnum->getName();
                    }
                }
                $operator = is_array($value) ? 'IN' : '=';
                $query->andWhere("e.$key $operator (:$key)")
                    ->setParameter($key, $value);
            }
        }

        // Apply Smart Context (localized filters) if not global and not explicitly overridden
        if (! $isGlobal) {
            $envChannel = getenv('API_SOURCE');
            $envEntity = getenv('API_ENTITY');
            $envStart = getenv('START_DATE');
            $envEnd = getenv('END_DATE');

            if ($envChannel && $envChannel !== 'none' && (! is_object($filters) || ! isset($filters->channel))) {
                if ($chanEnum = Channel::tryFromName($envChannel)) {
                    $envChannel = $chanEnum->getName();
                }
                $query->andWhere('e.channel = :ctx_channel')->setParameter('ctx_channel', $envChannel);
            }
            if ($envEntity && $envEntity !== 'none' && (! is_object($filters) || ! isset($filters->entity))) {
                $equivalents = [$envEntity];
                if (str_starts_with($envEntity, 'channeled_')) {
                    $equivalents[] = str_replace('channeled_', '', $envEntity);
                } else {
                    $equivalents[] = 'channeled_'.$envEntity;
                }
                $query->andWhere('e.entity IN (:ctx_entities)')->setParameter('ctx_entities', array_unique($equivalents));
            }

            // Differentiate by Date Range in payload
            // We use a loose LIKE pattern to be compatible with MySQL JSON columns.
            // In PostgreSQL, this is handled via Native SQL in the calling methods to avoid DQL parsing issues.
            if (! Helpers::isPostgres()) {
                $payloadField = 'e.payload';
                if ($envStart && (! is_object($filters) || ! isset($filters->startDate))) {
                    $query->andWhere("($payloadField LIKE :ctx_start_pattern1 OR $payloadField LIKE :ctx_start_pattern2)")
                        ->setParameter('ctx_start_pattern1', '%startDate%'.$envStart.'%')
                        ->setParameter('ctx_start_pattern2', '%start_date%'.$envStart.'%');
                }
                if ($envEnd && (! is_object($filters) || ! isset($filters->endDate))) {
                    $query->andWhere("($payloadField LIKE :ctx_end_pattern1 OR $payloadField LIKE :ctx_end_pattern2)")
                        ->setParameter('ctx_end_pattern1', '%endDate%'.$envEnd.'%')
                        ->setParameter('ctx_end_pattern2', '%end_date%'.$envEnd.'%');
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
                        $value = (int)$value;
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
                        $value = $chanEnum->getName();
                    }
                }
                $operator = is_array($value) ? 'IN' : '=';
                $query->andWhere("e.$key $operator (:$key)")
                    ->setParameter($key, $value);
            }
        }

        if (! $isGlobal) {
            $envChannel = getenv('API_SOURCE');
            if ($envChannel && (! is_object($filters) || ! isset($filters->channel))) {
                if ($chanEnum = Channel::tryFromName($envChannel)) {
                    $envChannel = $chanEnum->getName();
                }
                $query->andWhere('e.channel = :ctx_channel')->setParameter('ctx_channel', $envChannel);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return (int)$query->getQuery()->getSingleScalarResult();
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
     * @throws \Exception
     */
    public function getJobs(): array
    {
        return $this->readMultiple()->toArray();
    }

    /**
     * @param int|int[] $status
     * @param string|null $channel
     * @param string|null $instanceName
     * @param int|null $workerTier
     * @return Job[]
     * @throws Exception
     */
    public function getJobsByStatus(array|int $status, ?string $channel = null, ?string $instanceName = null, ?int $workerTier = null): array
    {
        if (Helpers::isPostgres()) {
            $isBatch = is_array($status);
            if ($isBatch) {
                $statusPlaceholders = [];
                $params = [];
                foreach ($status as $i => $s) {
                    $placeholder = "status_".$i;
                    $statusPlaceholders[] = ":".$placeholder;
                    $params[$placeholder] = $s;
                }
                $statusSql = "IN (".implode(', ', $statusPlaceholders).")";
            } else {
                $statusSql = "= :status";
                $params = ['status' => $status];
            }

            $processingStatus = JobStatus::processing->value;
            $sql = "
                WITH RankedJobs AS (
                    SELECT j.*, 
                           ROW_NUMBER() OVER(
                               PARTITION BY COALESCE(CAST(j.payload AS JSONB)->>'account_id', CAST(j.payload AS JSONB)->'params'->>'account_id'), j.payload->>'instance_name'
                               ORDER BY j.priority DESC, j.id ASC
                           ) as account_rank
                    FROM jobs j
                    LEFT JOIN channels c ON j.channel = c.name
                    WHERE j.status {$statusSql}
                    ".($channel ? " AND j.channel = :channel" : "")."
                    ".($instanceName && $instanceName !== 'global'
                    ? " AND (CAST(j.payload AS JSONB)->>'instance_name' = :instance_name OR CAST(j.payload AS text) LIKE :instance_name_pattern)"
                    : ""
            )."
                    ".($workerTier !== null ? " AND COALESCE(c.tier, 2) = :worker_tier" : "")."
                    AND NOT EXISTS (
                        SELECT 1 FROM jobs p 
                        WHERE p.status = $processingStatus 
                        AND (
                            (COALESCE(CAST(p.payload AS JSONB)->>'account_id', CAST(p.payload AS JSONB)->'params'->>'account_id') = COALESCE(CAST(j.payload AS JSONB)->>'account_id', CAST(j.payload AS JSONB)->'params'->>'account_id') 
                             AND COALESCE(CAST(j.payload AS JSONB)->>'account_id', CAST(j.payload AS JSONB)->'params'->>'account_id') IS NOT NULL)
                            OR (COALESCE(CAST(p.payload AS JSONB)->>'instance_name', '') = COALESCE(CAST(j.payload AS JSONB)->>'instance_name', '') 
                                AND COALESCE(CAST(j.payload AS JSONB)->>'account_id', CAST(j.payload AS JSONB)->'params'->>'account_id') IS NULL)
                        )
                    )
                )
                SELECT * FROM RankedJobs 
                WHERE account_rank <= 5
                ORDER BY priority DESC, id ASC 
                LIMIT 100";

            if ($channel) {
                $params['channel'] = $channel;
            }
            if ($instanceName && $instanceName !== 'global') {
                $params['instance_name'] = $instanceName;
                $params['instance_name_pattern'] = '%instance_name%'.$instanceName.'%';
            }
            if ($workerTier !== null) {
                $params['worker_tier'] = $workerTier;
            }

            $rsm = new ResultSetMappingBuilder($this->_em);
            $rsm->addRootEntityFromClassMetadata($this->getEntityName(), 'j');

            $query = $this->_em->createNativeQuery($sql, $rsm);
            $query->setParameters($params);

            return $query->getResult();
        }

        $qb = $this->createQueryBuilder('j');

        if (is_array($status)) {
            $qb->andWhere('j.status IN (:status)')->setParameter('status', $status);
        } else {
            $qb->andWhere('j.status = :status')->setParameter('status', $status);
        }

        if ($channel) {
            $qb->andWhere('j.channel = :channel')->setParameter('channel', $channel);
        }

        if ($instanceName && $instanceName !== 'global') {
            $qb->andWhere('j.payload LIKE :instance_pattern')
                ->setParameter('instance_pattern', '%instance_name%'.$instanceName.'%');
        }

        if ($workerTier !== null) {
            // Not supported cleanly in standard doctrine DQL without joins, but sqlite logic fallback
        }

        return $qb->orderBy('j.priority', 'DESC')->addOrderBy('j.id', 'ASC')->setMaxResults(100)->getQuery()->getResult();
    }

    /**
     * @param string $uuid
     * @return array
     * @throws \Exception
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
     * @param bool $returnEntity
     * @return bool|array|Entity|null
     * @throws OptimisticLockException
     * @throws ORMException
     */
    public function update(int $id, ?object $data = null, bool $returnEntity = false): bool|array|null|Entity
    {
        $dataArr = (array)($data ?? []);
        if (isset($dataArr['status']) && $dataArr['status']) {
            $statusValue = $dataArr['status'];
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
                $dataArr['status'] = $mappedStatus->value;
            }
            $data = (object)$dataArr;
        }

        $result = parent::update($id, $data, $returnEntity);

        if ($result) {
            try {
                $job = $this->find($id);
                if ($job) {
                    $redis = Helpers::getRedisClient();
                    $cache = CacheService::getInstance($redis);
                    $channel = $job->getChannel();
                    $payload = $job->getPayload();
                    $accountId = $payload['params']['account_id'] ?? null;

                    $cache->delete('sync_telemetry:global');
                    if ($channel) {
                        $cache->delete('sync_telemetry:channel:'.$channel);
                        if ($accountId) {
                            $cache->delete('sync_telemetry:channel:'.$channel.':'.$accountId);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Silently fail cache invalidation
            }
        }

        return $result;
    }

    /**
     * Atomically claims a job by moving it from 'scheduled' to 'processing'.
     * Returns true if the claim was successful.
     *
     * @param int $id
     * @param string|null $workerId
     * @return bool
     */
    public function claimJob(int $id, ?string $workerId = null): bool
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':processing')
            ->set('e.updatedAt', ':now');

        if ($workerId) {
            $qb->set('e.workerId', ':workerId')
                ->setParameter('workerId', $workerId);
        }

        $updatedRows = $qb->where('e.id = :id')
            ->andWhere($qb->expr()->in('e.status', ':claimable'))
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('now', new DateTime())
            ->setParameter('id', $id)
            ->setParameter('claimable', [JobStatus::scheduled->value, JobStatus::delayed->value])
            ->getQuery()
            ->execute();

        return (int)$updatedRows > 0;
    }

    /**
     * Resets stuck jobs by instance name.
     *
     * @param string $instanceName
     * @return int
     * @throws ConfigurationException
     * @throws Exception
     */
    public function resetStuckJobsByInstance(string $instanceName): int
    {
        if (Helpers::isPostgres()) {
            $sql = "UPDATE jobs SET status = :scheduled, updated_at = :now WHERE status = :processing AND CAST(payload AS text) LIKE :instance_pattern";

            return $this->_em->getConnection()->executeStatement($sql, [
                'scheduled' => JobStatus::scheduled->value,
                'now' => (new DateTime())->format('Y-m-d H:i:s'),
                'processing' => JobStatus::processing->value,
                'instance_pattern' => '%instance_name%'.$instanceName.'%',
            ]);
        }

        $qb = $this->_em->createQueryBuilder();

        return $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':scheduled')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.payload LIKE :instance_pattern')
            ->setParameter('scheduled', JobStatus::scheduled->value)
            ->setParameter('now', new DateTime())
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('instance_pattern', '%instance_name%'.$instanceName.'%')
            ->getQuery()
            ->execute();
    }

    /**
     * Resets jobs held by workers that are no longer active.
     *
     * @param array $activeWorkerIds
     * @return int
     */
    public function resetJobsByDeadWorkers(array $activeWorkerIds): int
    {
        if (empty($activeWorkerIds)) {
            return 0;
        }

        $qb = $this->createQueryBuilder('e');
        $count = $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':scheduled')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.workerId NOT IN (:activeWorkers)')
            ->andWhere('e.workerId IS NOT NULL')
            ->setParameter('scheduled', JobStatus::scheduled->value)
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('now', new DateTime())
            ->setParameter('activeWorkers', $activeWorkerIds)
            ->getQuery()
            ->execute();

        return (int)$count;
    }

    /**
     * Resets ALL jobs that have been stuck in processing for too long.
     * This is a safety net for orphaned jobs when workers crash or are renamed.
     *
     * @param int $timeoutMinutes
     * @return int
     * @throws \DateMalformedStringException
     */
    public function resetAllOrphanedJobs(int $timeoutMinutes = 30): int
    {
        $threshold = new DateTime();
        $threshold->modify("-$timeoutMinutes minutes");

        $qb = $this->createQueryBuilder('e');
        $count = $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':scheduled')
            ->set('e.workerId', 'NULL')
            ->set('e.message', ':message')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.updatedAt < :threshold')
            ->setParameter('scheduled', JobStatus::scheduled->value)
            ->setParameter('message', "Rescheduled orphaned job (timed out after {$timeoutMinutes} minutes)")
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('now', new DateTime())
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return (int)$count;
    }

    /**
     * Resets jobs held by a specific worker ID that are stuck in processing.
     * @param string $workerId
     * @return int
     */
    public function resetStuckJobsByWorker(string $workerId): int
    {
        $qb = $this->_em->createQueryBuilder();

        return $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':scheduled')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.workerId = :workerId')
            ->setParameter('scheduled', JobStatus::scheduled->value)
            ->setParameter('now', new DateTime())
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('workerId', $workerId)
            ->getQuery()
            ->execute();
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
            ->setParameter('now', new DateTime())
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
            ->setParameter('now', new DateTime())
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();

        return (int)$updatedRows > 0;
    }

    /**
     * @param string $instanceName
     * @param int $withinHours
     * @return bool
     * @throws ConfigurationException
     * @throws Exception
     * @throws \DateMalformedStringException
     */
    public function hasSuccessfulRecentJob(string $instanceName, int $withinHours = 24): bool
    {
        if (Helpers::isPostgres()) {
            $since = new DateTime();
            $since->modify("-$withinHours hours");

            $sql = "SELECT count(j.id) FROM jobs j WHERE CAST(j.payload AS text) LIKE :instance_name_pattern AND j.status = :completed AND j.updated_at >= :since";

            $result = $this->_em->getConnection()->executeQuery($sql, [
                'instance_name_pattern' => '%instance_name%'.$instanceName.'%',
                'completed' => JobStatus::completed->value,
                'since' => $since->format('Y-m-d H:i:s'),
            ]);

            return (int)$result->fetchOne() > 0;
        }

        $qb = $this->_em->createQueryBuilder();
        $since = new DateTime();
        $since->modify("-$withinHours hours");

        $payloadField = 'e.payload';

        $count = $qb->select('count(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where("$payloadField LIKE :instance_name_pattern")
            ->andWhere('e.status = :completed')
            ->andWhere('e.updatedAt >= :since')
            ->setParameter('instance_name_pattern', '%instance_name%'.$instanceName.'%')
            ->setParameter('completed', JobStatus::completed->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$count > 0;
    }

    /**
     * @param string $instanceName
     * @return DateTime|null
     * @throws ConfigurationException
     * @throws Exception
     * @throws \DateMalformedStringException
     */
    public function getLastSuccessfulJobTime(string $instanceName): ?DateTime
    {
        if (Helpers::isPostgres()) {
            $sql = "SELECT j.updated_at FROM jobs j WHERE CAST(j.payload AS text) LIKE :instance_name_pattern AND j.status = :completed ORDER BY j.updated_at DESC LIMIT 1";

            $result = $this->_em->getConnection()->executeQuery($sql, [
                'instance_name_pattern' => '%instance_name%'.$instanceName.'%',
                'completed' => JobStatus::completed->value,
            ]);

            $val = $result->fetchOne();

            return $val ? new DateTime($val) : null;
        }

        $qb = $this->_em->createQueryBuilder();
        $payloadField = 'e.payload';

        $job = $qb->select('e')
            ->from($this->getEntityName(), 'e')
            ->where("{$payloadField} LIKE :instance_name_pattern")
            ->andWhere('e.status = :completed')
            ->setParameter('instance_name_pattern', '%instance_name%'.$instanceName.'%')
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
     * @throws ConfigurationException
     * @throws Exception
     */
    public function isAnotherJobProcessing(string $instanceName, ?int $excludeJobId = null): bool
    {
        if (Helpers::isPostgres()) {
            $sql = "SELECT count(j.id) FROM jobs j WHERE (j.payload->>'instance_name') = :instance_name AND j.status = :processing";
            $params = [
                'instance_name' => $instanceName,
                'processing' => JobStatus::processing->value,
            ];

            if ($excludeJobId) {
                $sql .= " AND j.id != :excludeId";
                $params['excludeId'] = $excludeJobId;
            }

            return (int)$this->_em->getConnection()->fetchOne($sql, $params) > 0;
        }

        $qb = $this->_em->createQueryBuilder();
        $payloadField = 'e.payload';

        $qb->select('count(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where("$payloadField LIKE :instance_name_pattern")
            ->andWhere('e.status = :processing')
            ->setParameter('instance_name_pattern', '%instance_name%'.$instanceName.'%')
            ->setParameter('processing', JobStatus::processing->value);

        if ($excludeJobId) {
            $qb->andWhere('e.id != :excludeId')
                ->setParameter('excludeId', $excludeJobId);
        }

        return (int)$qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @throws ConfigurationException
     * @throws Exception
     */
    public function claimAvailableJob(mixed $status, ?string $workerId = null, ?string $channel = null, ?string $instanceName = null, ?int $workerTier = null): ?Job
    {
        if (! Helpers::isPostgres()) {
            $jobs = $this->getJobsByStatus($status, $channel, $instanceName, $workerTier);
            foreach ($jobs as $job) {
                if (! $this->canClaimMoreJobsForChannel($job->getChannel())) {
                    continue;
                }

                if ($this->claimJob($job->getId(), $workerId)) {
                    return $this->find($job->getId()) ?? $job;
                }
            }

            return null;
        }

        $statusSql = is_array($status) ? "IN (".implode(',', array_map('intval', $status)).")" : "= ".(int)$status;
        $processingStatus = JobStatus::processing->value;

        $isFreeTier = (getenv('BILLING_TIER') === 'free');

        $this->_em->beginTransaction();

        try {
            $sql = "
                WITH BusyChannels AS (
                    SELECT j.channel as channel_name, COUNT(*) as active_count,
                           ".($isFreeTier ? "1" : "MAX(COALESCE(
                               NULLIF(to_jsonb(c)->>'max_workers', '')::int,
                               NULLIF(to_jsonb(c)->>'maxworkers', '')::int,
                               3
                           ))")." as max_workers
                    FROM jobs j
                    JOIN channels c ON j.channel = c.name
                    WHERE j.status = {$processingStatus}
                    GROUP BY j.channel
                    HAVING COUNT(*) >= ".($isFreeTier ? "1" : "MAX(COALESCE(
                        NULLIF(to_jsonb(c)->>'max_workers', '')::int,
                        NULLIF(to_jsonb(c)->>'maxworkers', '')::int,
                        3
                    ))")."
                ),
                ProcessingAccounts AS (
                    SELECT DISTINCT channel, entity, 
                           COALESCE(payload->>'account_id', payload->'params'->>'account_id') as account_id,
                           COALESCE(payload->>'instance_name', '') as instance_name
                    FROM jobs
                    WHERE status = {$processingStatus}
                ),
                ChannelSlots AS (
                    -- Acquire a channel-level advisory lock to prevent concurrent workers from
                    -- racing past BusyChannels before any of them has updated updated_at.
                    SELECT DISTINCT j.channel,
                           pg_try_advisory_xact_lock(hashtext('channel_slot|' || j.channel)) as slot_acquired
                    FROM jobs j
                    LEFT JOIN BusyChannels bc ON j.channel = bc.channel_name
                    WHERE j.status {$statusSql}
                    AND bc.channel_name IS NULL
                    ".($channel ? " AND j.channel = :channel" : "")."
                ),
                RankedJobs AS (
                    SELECT j.id
                    FROM jobs j
                    LEFT JOIN channels c ON j.channel = c.name
                    LEFT JOIN BusyChannels bc ON j.channel = bc.channel_name
                    JOIN ChannelSlots cs ON j.channel = cs.channel AND cs.slot_acquired = true
                    WHERE j.status {$statusSql}
                    -- Limit Guard: Skip if channel is busy
                    AND bc.channel_name IS NULL
                    ".($channel ? " AND j.channel = :channel" : "")."
                    ".($instanceName && $instanceName !== 'global'
                    ? " AND (j.payload->>'instance_name' = :instance_name OR j.payload::text LIKE :instance_name_pattern)"
                    : ""
            )."
                    ".($workerTier !== null ? " AND COALESCE(c.tier, 2) = :worker_tier" : "")."
                    -- Mutual Exclusion
                    AND NOT EXISTS (
                        SELECT 1 FROM ProcessingAccounts p 
                        WHERE p.channel = j.channel AND p.entity = j.entity
                        AND (
                            (p.account_id = COALESCE(j.payload->>'account_id', j.payload->'params'->>'account_id') AND COALESCE(j.payload->>'account_id', j.payload->'params'->>'account_id') IS NOT NULL)
                            OR 
                            (p.instance_name = COALESCE(j.payload->>'instance_name', '') AND COALESCE(j.payload->>'account_id', j.payload->'params'->>'account_id') IS NULL)
                        )
                    )
                    ORDER BY j.priority DESC, j.id ASC
                    LIMIT 1
                    FOR UPDATE OF j SKIP LOCKED
                )
                UPDATE jobs SET status = {$processingStatus}, worker_id = :worker_id, updated_at = NOW()
                WHERE id = (SELECT id FROM RankedJobs)
                -- Job-level advisory lock (prevents two workers claiming the exact same job)
                AND pg_try_advisory_xact_lock(hashtext(channel || '|' || entity || '|' || COALESCE(payload->>'account_id', payload->'params'->>'account_id', payload->>'instance_name', 'global')))
                RETURNING id";

            $params = [
                'worker_id' => $workerId,
            ];
            if ($channel) {
                $params['channel'] = $channel;
            }
            if ($instanceName && $instanceName !== 'global') {
                $params['instance_name'] = $instanceName;
                $params['instance_name_pattern'] = '%instance_name%'.$instanceName.'%';
            }
            if ($workerTier !== null) {
                $params['worker_tier'] = $workerTier;
            }

            $jobId = $this->_em->getConnection()->fetchOne($sql, $params);

            if ($jobId) {
                $this->_em->commit();

                return $this->find($jobId);
            }

            if ($this->_em->getConnection()->isTransactionActive()) {
                $this->_em->commit();
            }

            return null;
        } catch (Throwable $e) {
            if (isset($this->_em) && $this->_em->getConnection()->isTransactionActive()) {
                $this->_em->rollback();
            }
            error_log("Error in claimAvailableJob: ".$e->getMessage());

            return null;
        }
    }

    private function canClaimMoreJobsForChannel(string $channel): bool
    {
        $maxWorkers = $this->resolveChannelMaxWorkers($channel);
        if ($maxWorkers <= 0) {
            return false;
        }

        $activeJobs = (int)$this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.channel = :channel')
            ->andWhere('j.status = :processing')
            ->setParameter('channel', $channel)
            ->setParameter('processing', JobStatus::processing->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $activeJobs < $maxWorkers;
    }

    private function resolveChannelMaxWorkers(string $channel): int
    {
        if (getenv('BILLING_TIER') === 'free') {
            return 1;
        }
        
        try {
            $maxWorkers = $this->_em->createQueryBuilder()
                ->select('c.maxWorkers')
                ->from(Channel::class, 'c')
                ->where('c.name = :channel')
                ->setParameter('channel', $channel)
                ->setMaxResults(1)
                ->getQuery()
                ->getSingleScalarResult();

            return max(0, (int)$maxWorkers);
        } catch (Throwable) {
            return 3;
        }
    }

    /**
     * @param int $minutes
     * @return int
     * @throws \DateMalformedStringException
     */
    public function cleanupStuckJobs(int $minutes = 60): int
    {
        $since = new DateTime();
        $since->modify("-$minutes minutes");

        $qb = $this->_em->createQueryBuilder();

        return $qb->update($this->getEntityName(), 'e')
            ->set('e.status', ':failed')
            ->set('e.message', ':message')
            ->set('e.updatedAt', ':now')
            ->where('e.status = :processing')
            ->andWhere('e.updatedAt <= :since')
            ->setParameter('failed', JobStatus::failed->value)
            ->setParameter('message', "Job timed out after ".$minutes." minutes")
            ->setParameter('now', new DateTime())
            ->setParameter('processing', JobStatus::processing->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->execute();
    }
}
