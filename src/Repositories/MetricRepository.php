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

class MetricRepository extends BaseRepository
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
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('partial e.{id, value, metadata}')
                ->addSelect('partial mc.{id, channel, name, period, metricDate}'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        $query->from($this->getEntityName(), 'e')
            ->leftJoin('e.metricConfig', 'mc');

        if ($type !== QueryBuilderType::COUNT) {
            $query->addSelect('partial cm.{id, platformId, data}')
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
                ->addSelect('ds')
                ->addSelect('dv')
                ->addSelect('dk')
                ->leftJoin('e.channeledMetrics', 'cm')
                ->leftJoin('mc.channeledAccount', 'ca')
                ->leftJoin('mc.channeledCampaign', 'cc')
                ->leftJoin('mc.channeledAdGroup', 'cag')
                ->leftJoin('mc.channeledAd', 'cad')
                ->leftJoin('mc.query', 'q')
                ->leftJoin('mc.page', 'pa')
                ->leftJoin('mc.post', 'po')
                ->leftJoin('mc.product', 'pr')
                ->leftJoin('mc.customer', 'cu')
                ->leftJoin('mc.order', 'o')
                ->leftJoin('cm.dimensionSet', 'ds')
                ->leftJoin('ds.values', 'dv')
                ->leftJoin('dv.dimensionKey', 'dk');
        }

        return $query;
    }

    /**
     * @param int $id
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildReadQuery(
        int $id,
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder()
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if ($filters) {
            foreach ($filters as $key => $value) {
                $alias = $this->getFieldAlias($key);
                $query->andWhere($alias . '.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query;
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
     * @throws Exception
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

        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                $alias = $this->getFieldAlias($key);
                $query->andWhere($alias . '.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        $aliasOrderBy = $this->getFieldAlias($orderBy);
        $query->orderBy("$aliasOrderBy.$orderBy", strtoupper($orderDir))
            ->setMaxResults($limit)
            ->setFirstResult($limit * $pagination);

        return $query;
    }

    /**
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return int
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function countElements(
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $alias = $this->getFieldAlias($key);
                $query->andWhere($alias . '.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        try {
            return $query->getQuery()->getSingleScalarResult();
        } catch (NoResultException $e) {
            return 0;
        }
    }

    /**
     * @param string $field
     * @return string
     */
    protected function getFieldAlias(string $field): string
    {
        return in_array($field, ['id', 'value', 'dimensionsHash', 'metadata', 'channeledMetrics', 'metricConfig']) ? 'e' : 'mc';
    }

    public function getMaxMetricDateForChannelAndChanneledAccount(int $channel, int $channeledAccountId): ?string
    {
        $query = $this->_em->createQueryBuilder()
            ->select('MAX(mc.metricDate)')
            ->from(\Entities\Analytics\MetricConfig::class, 'mc')
            ->where('mc.channel = :channel')
            ->andWhere('IDENTITY(mc.channeledAccount) = :channeledAccount')
            ->setParameters([
                'channel' => $channel,
                'channeledAccount' => $channeledAccountId,
            ])
            ->getQuery();

        try {
            return $query->getSingleScalarResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function getMaxMetricDateForChannelAndPage(int $channel, int $pageId): ?string
    {
        $query = $this->_em->createQueryBuilder()
            ->select('MAX(mc.metricDate)')
            ->from(\Entities\Analytics\MetricConfig::class, 'mc')
            ->where('mc.channel = :channel')
            ->andWhere('IDENTITY(mc.page) = :page')
            ->setParameters([
                'channel' => $channel,
                'page' => $pageId,
            ])
            ->getQuery();

        try {
            return $query->getSingleScalarResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function existsByChannelAndName(int $channel, string $name, Period $period, DateTime $metricDate): bool
    {
        $query = $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->join('e.metricConfig', 'mc')
            ->where('mc.channel = :channel')
            ->andWhere('mc.name = :name')
            ->andWhere('mc.period = :period')
            ->andWhere('mc.metricDate = :metricDate')
            ->setParameters([
                'channel' => $channel,
                'name' => $name,
                'period' => $period->value,
                'metricDate' => $metricDate,
            ])
            ->getQuery();

        try {
            return $query->getSingleScalarResult() > 0;
        } catch (NoResultException $e) {
            return false;
        }
    }

    public function getByChannelAndName(
        int $channel,
        string $name,
        Period $period,
        DateTime $metricDate
    ): ?Metric {
        return $this->createQueryBuilder('m')
            ->join('m.metricConfig', 'mc')
            ->where('mc.channel = :channel')
            ->andWhere('mc.name = :name')
            ->andWhere('mc.period = :period')
            ->andWhere('mc.metricDate = :metricDate')
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

    public function findMetricsByPeriod(int $channel, string $name, Period $period, DateTime $start, DateTime $end): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.metricConfig', 'mc')
            ->where('mc.channel = :channel')
            ->andWhere('mc.name = :name')
            ->andWhere('mc.period = :period')
            ->andWhere('mc.metricDate BETWEEN :start AND :end')
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
            $configSignature = \Classes\KeyGenerator::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                metricDate: $metricDate,
                query: $queryEntity,
                page: $page,
                country: $country,
                device: $device,
                creative: $dimensions['creative'] ?? null,
            );

            $qb = $this->createQueryBuilder('m')
                ->join('m.metricConfig', 'mc')
                ->where('mc.channel = :channel')
                ->andWhere('mc.name = :name')
                ->andWhere('mc.period = :period')
                ->andWhere('mc.metricDate = :metricDate')
                ->setParameters([
                    'channel' => $channel,
                    'name' => $name,
                    'period' => $period->value,
                    'metricDate' => $metricDate->format('Y-m-d'),
                ]);

            if ($queryEntity) {
                $qb->andWhere('mc.query = :query')
                    ->setParameter('query', $queryEntity);
            } else {
                $qb->andWhere('mc.query IS NULL');
            }

            if ($page) {
                if (!$page->getId()) {
                    error_log("MetricRepository::findByChannelAndDimensions: Unmanaged Page: url=" . $page->getUrl() . ", trace=" . (new Exception())->getTraceAsString());
                    return null; // Skip query if page is unmanaged
                }
                $qb->andWhere('mc.page = :page')
                    ->setParameter('page', $page);
            } else {
                $qb->andWhere('mc.page IS NULL');
            }

            if ($country) {
                $qb->andWhere('mc.country = :country')
                    ->setParameter('country', $country);
            } else {
                $qb->andWhere('mc.country IS NULL');
            }

            if ($device) {
                $qb->andWhere('mc.device = :device')
                    ->setParameter('device', $device);
            } else {
                $qb->andWhere('mc.device IS NULL');
            }

            if (!empty($dimensions)) {
                $qb->join('m.channeledMetrics', 'cm');
                $i = 0;
                foreach ($dimensions as $key => $value) {
                    if (in_array($key, ['site', 'country', 'device'], true)) {
                        continue;
                    }
                    $dsAlias = "ds$i";
                    $dvAlias = "dv$i";
                    $dkAlias = "dk$i";
                    $qb->join('cm.dimensionSet', $dsAlias)
                       ->join("$dsAlias.values", $dvAlias)
                       ->join("$dvAlias.dimensionKey", $dkAlias)
                       ->andWhere("$dkAlias.name = :dkname_$i AND $dvAlias.value = :dvval_$i")
                       ->setParameter("dkname_$i", $key)
                       ->setParameter("dvval_$i", $value);
                    $i++;
                }
            }

            $query = $qb->setMaxResults(1)->getQuery();

            $result = $query->getOneOrNullResult();

            if ($result && $result->getPage() && !$result->getPage()->getId()) {
                error_log("MetricRepository::findByChannelAndDimensions: Returned Metric with unmanaged Page: url=" . ($result->getPage()->getUrl() ?? 'unknown') . ", trace=" . (new Exception())->getTraceAsString());
                return null; // Invalidate result
            }



            return $result;
        } catch (Exception $e) {
            error_log("Error in findByChannelAndDimensions: name=$name, channel=$channel, dimensions=" . json_encode($dimensions) . ", error=" . $e->getMessage() . ", trace=" . $e->getTraceAsString());
            throw $e;
        }
    }

    public function findMetricsByPeriodAndDimensions(
        int $channel,
        string $name,
        Period $period,
        DateTime $start,
        DateTime $end,
        array $dimensions
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->join('m.metricConfig', 'mc')
            ->join('m.channeledMetrics', 'cm')
            ->where('mc.channel = :channel')
            ->andWhere('mc.name = :name')
            ->andWhere('mc.period = :period')
            ->andWhere('mc.metricDate BETWEEN :start AND :end')
            ->setParameters([
                'channel' => $channel,
                'name' => $name,
                'period' => $period->value,
                'start' => $start,
                'end' => $end,
            ]);

        if (!empty($dimensions)) {
            $i = 0;
            foreach ($dimensions as $key => $value) {
                $dsAlias = "ds$i";
                $dvAlias = "dv$i";
                $dkAlias = "dk$i";
                $qb->join('cm.dimensionSet', $dsAlias)
                   ->join("$dsAlias.values", $dvAlias)
                   ->join("$dvAlias.dimensionKey", $dkAlias)
                   ->andWhere("$dkAlias.name = :dkname_$i AND $dvAlias.value = :dvval_$i")
                   ->setParameter("dkname_$i", $key)
                   ->setParameter("dvval_$i", $value);
                $i++;
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $startDate
     * @param string|null $endDate
     */
    protected function applyDateFilters(QueryBuilder $query, ?string $startDate, ?string $endDate): void
    {
        if (!$startDate && !$endDate) {
            return;
        }

        if ($startDate) {
            $query->andWhere("mc.metricDate >= :startDate")
                ->setParameter('startDate', $startDate);
        }
        if ($endDate) {
            $query->andWhere("mc.metricDate <= :endDate")
                ->setParameter('endDate', $endDate);
        }
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        if (isset($result['metricConfig'])) {
            $mc = $result['metricConfig'];
            unset($result['metricConfig']);
            $result = array_merge($result, $mc);
        }
        $result = $this->replaceChannelName($result);
        $result = $this->stripPositionWeighted($result);
        $result = $this->formatDate($result);
        $result = $this->flattenDimensions($result);
        return parent::processResult($result);
    }

    /**
     * Flattens normalized dimensions into the legacy 'dimensions' format for compatibility.
     */
    protected function flattenDimensions(array $result): array
    {
        if (isset($result['channeledMetrics'])) {
            foreach ($result['channeledMetrics'] as &$cm) {
                $dimensions = [];
                if (isset($cm['dimensionSet']['values'])) {
                    foreach ($cm['dimensionSet']['values'] as $val) {
                        $dimensions[] = [
                            'dimensionKey' => $val['dimensionKey']['name'] ?? 'unknown',
                            'dimensionValue' => $val['value'] ?? ''
                        ];
                    }
                }
                $cm['dimensions'] = $dimensions;
                unset($cm['dimensionSet']);
            }
        }
        return $result;
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
        $entity['channeledMetrics'] = array_map(function ($channelMetric) {
            unset($channelMetric['data']['position_weighted']);
            return $channelMetric;
        }, $entity['channeledMetrics']);
        unset($entity['query']['data']['position_weighted']);
        return $entity;
    }
}
