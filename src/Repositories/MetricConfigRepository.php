<?php

namespace Repositories;

use DateTime;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Query;
use Entities\Entity;
use Enums\Channel;
use Enums\Period;
use Enums\QueryBuilderType;
use Exception;
use Helpers\Helpers;
use ReflectionException;
class MetricConfigRepository extends BaseRepository
{
    /**
     * @param object|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): Entity|array|null
    {
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $entityName = $this->getEntityName();
                $entity = new $entityName();

                if ((array) $data) {
                    foreach ((array) $data as $key => $value) {
                        $setter = 'add' . Helpers::toCamelcase($key, true);
                        if (method_exists($entity, $setter)) {
                            // Special handling for 'page' to prevent unmanaged entities
                            if ($key === 'page' && $value instanceof Page && !$value->getId()) {
                                error_log("BaseRepository::create: Skipping unmanaged Page entity for {$entityName}: key={$key}, url=" . $value->getUrl());
                                continue; // Skip setting unmanaged Page
                            }
                            $entity->$setter($value);
                        }
                    }
                }

                $this->getEntityManager()->persist($entity);
                $this->getEntityManager()->flush();

                return $this->read(
                    id: $entity->getId(),
                    returnEntity: $returnEntity,
                );
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount); // Backoff: 100ms, 200ms, 300ms
                    continue;
                }
                error_log("BaseRepository::create failed after $maxRetries retries: {$e->getMessage()}");
                throw $e;
            }
        }
        return null;
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('partial e.{id, channel, name, period, metricDate}'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        return $query->addSelect('partial m.{id, value, metadata}')
            ->addSelect('partial cm.{id, platformId, data}')
            ->addSelect('ca')
            ->addSelect('cc')
            ->addSelect('cag')
            ->addSelect('cad')
            ->addSelect('partial q.{id, query, data}')
            ->addSelect('pa')
            ->addSelect('po')
            ->addSelect('pr')
            ->addSelect('cu')
            ->addSelect('o')
            ->addSelect('partial md.{id, dimensionKey, dimensionValue}')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledAccount', 'ca')
            ->leftJoin('e.channeledCampaign', 'cc')
            ->leftJoin('e.channeledAdGroup', 'cag')
            ->leftJoin('e.channeledAd', 'cad')
            ->leftJoin('e.query', 'q')
            ->leftJoin('e.page', 'pa')
            ->leftJoin('e.post', 'po')
            ->leftJoin('e.product', 'pr')
            ->leftJoin('e.customer', 'cu')
            ->leftJoin('e.order', 'o')
            ->leftJoin('e.metrics', 'm')
            ->leftJoin('m.channeledMetrics', 'cm')
            ->leftJoin('cm.dimensions', 'md');
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByChannelAndName(int $channel, string $name, Period $period, DateTime $metricDate): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.channel = :channel')
                ->andWhere('e.name = :name')
                ->andWhere('e.period = :period')
                ->andWhere('e.metricDate = :metricDate')
                ->setParameters([
                    'channel' => $channel,
                    'name' => $name,
                    'period' => $period->value,
                    'metricDate' => $metricDate,
                ])
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @throws NonUniqueResultException
     */
    public function getByChannelAndName(
        int $channel,
        string $name,
        Period $period,
        DateTime $metricDate
    ): ?Metric
    {
        return $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.name = :name')
            ->andWhere('m.period = :period')
            ->andWhere('m.metricDate = :metricDate')
            ->setParameters([
                'channel' => $channel,
                'name' => $name,
                'period' => $period->value,
                'metricDate' => $metricDate,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findMetricConfigsByPeriod(int $channel, string $name, Period $period, DateTime $start, DateTime $end): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.name = :name')
            ->andWhere('m.period = :period')
            ->andWhere('m.metricDate BETWEEN :start AND :end')
            ->setParameters([
                'channel' => $channel,
                'name' => $name,
                'period' => $period->value,
                'start' => $start,
                'end' => $end,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findByChannelAndDimensions(
        int $channel, // Changed from int to string
        string $name,
        Period $period,
        DateTime $metricDate,
        array $dimensions,
        ?Query $queryEntity = null,
        ?Page $page = null,
        ?Country $country = null,
        ?Device $device = null
    ): ?Metric {
        try {
            $qb = $this->createQueryBuilder('e')
                ->where('e.channel = :channel')
                ->andWhere('e.name = :name')
                ->andWhere('e.period = :period')
                ->andWhere('e.metricDate = :metricDate')
                ->setParameters([
                    'channel' => $channel,
                    'name' => $name,
                    'period' => $period->value,
                    'metricDate' => $metricDate->format('Y-m-d'),
                ]);

            if ($queryEntity) {
                $qb->andWhere('e.query = :query')
                    ->setParameter('query', $queryEntity);
            } else {
                $qb->andWhere('e.query IS NULL');
            }

            if ($page) {
                if (!$page->getId()) {
                    error_log("MetricRepository::findByChannelAndDimensions: Unmanaged Page: url=" . $page->getUrl() . ", trace=" . (new Exception())->getTraceAsString());
                    return null; // Skip query if page is unmanaged
                }
                $qb->andWhere('e.page = :page')
                    ->setParameter('page', $page);
            } else {
                $qb->andWhere('e.page IS NULL');
            }

            if ($country) {
                $qb->andWhere('e.country = :country')
                    ->setParameter('country', $country);
            } else {
                $qb->andWhere('e.country IS NULL');
            }

            if ($device) {
                $qb->andWhere('e.device = :device')
                    ->setParameter('device', $device);
            } else {
                $qb->andWhere('e.device IS NULL');
            }

            if (!empty($dimensions)) {
                $qb->leftJoin('e.metrics', 'm')
                    ->leftJoin('m.channeledMetrics', 'cm')
                    ->leftJoin('cm.dimensions', 'cmd');
                foreach ($dimensions as $key => $value) {
                    if (in_array($key, ['site', 'country', 'device'], true)) {
                        continue;
                    }
                    $qb->andWhere('cmd.dimensionKey = :dimensionKey_' . $key . ' AND cmd.dimensionValue = :dimensionValue_' . $key)
                        ->setParameter('dimensionKey_' . $key, $key)
                        ->setParameter('dimensionValue_' . $key, $value);
                }
            }

            $query = $qb->setMaxResults(1)->getQuery();

            $dql = $query->getDQL();
            error_log("findByChannelAndDimensions DQL: $dql");
            error_log("findByChannelAndDimensions Parameters: " . json_encode($query->getParameters()->toArray()));

            $result = $query->getOneOrNullResult();

            if ($result && $result->getPage() && !$result->getPage()->getId()) {
                error_log("MetricRepository::findByChannelAndDimensions: Returned Metric with unmanaged Page: url=" . $result->getPage()->getUrl() . ", trace=" . (new Exception())->getTraceAsString());
                return null; // Invalidate result
            }

            error_log("findByChannelAndDimensions: name=$name, channel=$channel, query_id=" . ($queryEntity ? $queryEntity->getId() : 'null') . ", page_id=" . ($page ? $page->getId() : 'null') . ", country_id=" . ($country ? $country->getId() : 'null') . ", device_id=" . ($device ? $device->getId() : 'null') . ", dimensions=" . json_encode($dimensions) . ", result=" . ($result ? 'found' : 'null'));

            return $result;
        } catch (Exception $e) {
            error_log("Error in findByChannelAndDimensions: name=$name, channel=$channel, dimensions=" . json_encode($dimensions) . ", error=" . $e->getMessage() . ", trace=" . $e->getTraceAsString());
            throw $e;
        }
    }

    public function findMetricConfigsByPeriodAndDimensions(
        int $channel,
        string $name,
        Period $period,
        DateTime $start,
        DateTime $end,
        array $dimensions
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->join('e.metrics', 'm')
            ->join('m.channeledMetrics', 'cm')
            ->join('cm.dimensions', 'cmd')
            ->where('e.channel = :channel')
            ->andWhere('e.name = :name')
            ->andWhere('e.period = :period')
            ->andWhere('e.metricDate BETWEEN :start AND :end')
            ->setParameters([
                'channel' => $channel,
                'name' => $name,
                'period' => $period->value,
                'start' => $start,
                'end' => $end,
            ]);

        foreach ($dimensions as $key => $value) {
            $alias = 'd'.$key;
            $qb->join('cm.dimensions', $alias, 'WITH', "$alias.dimensionKey = :dimensionKey_$key AND $alias.dimensionValue = :dimensionValue_$key")
                ->setParameter("dimensionKey_$key", $key)
                ->setParameter("dimensionValue_$key", $value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->replaceChannelName($result);
        $result = $this->stripPositionWeighted($result);
        return $this->formatDate($result);
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channel'] = Channel::from($entity['channel'])->getName();
        return $entity;
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function formatDate(array $entity): array
    {
        $entity['metricDate'] = $entity['metricDate']->format('Y-m-d');
        return $entity;
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function stripPositionWeighted(array $entity): array
    {
        $entity['metrics'] = array_map(function($metric) {
            $metric['channeledMetrics'] = array_map(function($channelMetric) {
                unset($channelMetric['data']['position_weighted']);
                return $channelMetric;
            }, $metric['channeledMetrics']);
            unset($metric['query']['data']['position_weighted']);
        }, $entity['metrics']);
        return $entity;
    }
}