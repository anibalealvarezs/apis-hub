<?php

namespace Repositories\Channeled;

use DateTime;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Metric;
use Entities\Entity;
use Entities\Analytics\Channel;
use Enums\QueryBuilderType;

class ChanneledMetricRepository extends ChanneledBaseRepository
{
    /** Controls whether the raw JSON `data` field is included in results */
    private bool $includeRawData = false;

    /**
     * @param bool $include
     * @return static
     */
    public function setIncludeRawData(bool $include): static
    {
        $this->includeRawData = $include;
        return $this;
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT  => $query->select('count(e.id)'),
            QueryBuilderType::LAST   => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
            QueryBuilderType::AGGREGATE => $query,
            QueryBuilderType::CUSTOM => null,
        };

        $query->from($this->getEntityName(), 'e');

        if ($type === QueryBuilderType::SELECT || $type === QueryBuilderType::AGGREGATE || $type === QueryBuilderType::LAST) {
            $query->addSelect('partial m.{id, value, metricDate, dimensionsHash, metadata}')
                ->addSelect('partial mc.{id, channel, name, period}')
                ->addSelect('ds')
                ->leftJoin('e.metric', 'm')
                ->leftJoin('m.metricConfig', 'mc')
                ->leftJoin('e.dimensionSet', 'ds');
        }

        return $query;
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        // Flatten nested metric → metricConfig into the root of the result
        if (isset($result['metric'])) {
            $metric = $result['metric'];
            unset($result['metric']);

            if (isset($metric['metricConfig'])) {
                $mc = $metric['metricConfig'];
                unset($metric['metricConfig']);
                // Flatten metricConfig fields to root
                $result = array_merge($result, $mc);
            }

            // Merge remaining metric fields (value, dimensionsHash, metadata)
            $result['metricId']       = $metric['id'] ?? null;
            $result['value']          = $metric['value'] ?? null;
            $result['dimensionsHash'] = $metric['dimensionsHash'] ?? null;
            $result['metadata']       = $metric['metadata'] ?? null;
        }

        // Replace channel int with its name
        if (isset($result['channel'])) {
            $result['channel'] = Channel::from($result['channel'])->getName();
        }

        // Format metricDate
        if (isset($result['metricDate']) && $result['metricDate'] instanceof \DateTimeInterface) {
            $result['metricDate'] = $result['metricDate']->format('Y-m-d');
        }

        // Strip raw data JSON unless explicitly requested
        if (!$this->includeRawData) {
            unset($result['data']);
        }

        return parent::processResult($result);
    }


    /**
     * @param object{
     *     platformId: string|int,
     *     channel: int,
     *     platformCreatedAt?: string|\DateTime|null,
     *     metric: \Entities\Analytics\Metric,
     *     data?: array|null,
     *     dimensions?: array<object{dimensionKey: string, dimensionValue: string}>
     * }|null $data
     * @param bool $returnEntity
     * @return ChanneledMetric|array|null
     * @throws OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): ChanneledMetric|array|null
    {
        $data = (array) ($data ?? []);
        if (!isset($data['platformId']) || !isset($data['channel'])) {
            return null;
        }
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $channeledMetric = new ChanneledMetric();
                $platformCreatedAt = $data['platformCreatedAt'] ?? 'now';
                $channeledMetric->addPlatformId($data['platformId'])
                    ->addChannel($data['channel'])
                    ->addPlatformCreatedAt(
                        $platformCreatedAt instanceof DateTime
                            ? $platformCreatedAt
                            : new DateTime($platformCreatedAt)
                    )
                    ->addMetric($data['metric'])
                    ->addData($data['data'] ?? null);

                if (isset($data['dimensions']) && !empty($data['dimensions'])) {
                    $dimManager = new \Classes\DimensionManager($this->getEntityManager());
                    $dimensionSet = $dimManager->resolveDimensionSet((array) $data['dimensions']);
                    $channeledMetric->setDimensionSet($dimensionSet);
                }

                $this->getEntityManager()->persist($channeledMetric);
                $this->getEntityManager()->flush();

                return $returnEntity ? $channeledMetric : null;
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    error_log("ChanneledMetricRepository::create retry $retryCount/$maxRetries due to OptimisticLockException: {$e->getMessage()}");
                    continue;
                }
                error_log("ChanneledMetricRepository::create failed after $maxRetries retries: {$e->getMessage()}");
                throw $e;
            }
        }
        return null;
    }

    /**
     * @param int|string $platformId
     * @param int $channel
     * @param Metric $metric
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByPlatformIdAndMetric(int|string $platformId, int $channel, Metric $metric): ?Entity
    {
        $channelValue = $this->validateChannel($channel);
        return $this->createBaseQueryBuilder()
            ->where('e.platformId = :platformId')
            ->setParameter('platformId', $platformId)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channelValue)
            ->andWhere('e.metric = :metric')
            ->setParameter('metric', $metric)
            ->getQuery()
            ->getOneOrNullResult(hydrationMode: AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param int|string $platformId
     * @param int $channel
     * @param Metric $metric
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsByPlatformIdAndMetric(int|string $platformId, int $channel, Metric $metric): bool
    {
        $channelValue = $this->validateChannel($channel);
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.platformId = :platformId')
                ->setParameter('platformId', $platformId)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channelValue)
                ->andWhere('e.metric = :metric')
                ->setParameter('metric', $metric)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param int $channel
     * @param string $siteKey
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getLastByPlatformCreatedAtForSite(int $channel, string $siteKey): ?array
    {
        return $this->createQueryBuilder('cm')
            ->select('cm.platformCreatedAt')
            ->where('cm.channel = :channel')
            ->andWhere('cm.platformId LIKE :siteKey')
            ->setParameter('channel', $channel)
            ->setParameter('siteKey', '%' . $siteKey . '%')
            ->orderBy('cm.platformCreatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
