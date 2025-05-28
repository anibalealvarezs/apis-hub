<?php

namespace Repositories\Channeled;

use DateTime;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledMetricDimension;
use Entities\Analytics\Metric;
use Entities\Entity;
use Enums\QueryBuilderType;
use stdClass;

class ChanneledMetricRepository extends ChanneledBaseRepository
{
    public function create(?stdClass $data = null, bool $returnEntity = false): ?ChanneledMetric
    {
        if (!$data || !isset($data->platformId) || !isset($data->channel)) {
            return null;
        }
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $channeledMetric = new ChanneledMetric();
                $channeledMetric->addPlatformId($data->platformId)
                    ->addChannel($data->channel)
                    ->addPlatformCreatedAt(
                        $data->platformCreatedAt instanceof DateTime
                            ? $data->platformCreatedAt
                            : new DateTime($data->platformCreatedAt ?? 'now')
                    )
                    ->addMetric($data->metric)
                    ->addData($data->data ?? null);

                if (isset($data->dimensions) && is_array($data->dimensions)) {
                    foreach ($data->dimensions as $dimensionData) {
                        $dimension = new ChanneledMetricDimension();
                        $dimension->addDimensionKey($dimensionData->dimensionKey)
                            ->addDimensionValue($dimensionData->dimensionValue)
                            ->addChanneledMetric($channeledMetric);
                        $channeledMetric->addDimension($dimension);
                    }
                }

                $this->getEntityManager()->persist($channeledMetric); // Use $_em
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

    public function findByDimension(int $channel, string $key, string $value): array
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.dimensions', 'cmd')
            ->where('cm.channel = :channel')
            ->andWhere('cmd.key = :key')
            ->andWhere('cmd.value = :value')
            ->setParameters([
                'channel' => $channel,
                'key' => $key,
                'value' => $value,
            ])
            ->getQuery()
            ->getResult();
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