<?php

namespace Repositories;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Metric;
use Entities\Analytics\Query;
use Enums\QueryBuilderType;
use Exception;

/**
 * Repository for Query entities, providing methods to query and manage search queries.
 */
class QueryRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('partial e.{id, query, data}'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    public function findByQuery(string $query): ?Query
    {
        return $this->createQueryBuilder('q')
            ->where('q.query = :query')
            ->setParameter('query', $query)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByDataAttribute(string $key, string $value, int $limit = 100): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.data->>:key = :value')
            ->setParameter('key', $key)
            ->setParameter('value', $value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByCampaign(string $campaignId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('q')
            ->from(Query::class, 'q')
            ->join(Metric::class, 'm', Join::WITH, 'm.query = q')
            ->join(ChanneledCampaign::class, 'cc', Join::WITH, 'm.channeledCampaign = cc')
            ->join(Campaign::class, 'c', Join::WITH, 'cc.campaign = c')
            ->where('c.campaignId = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function findByMetricValue(string $metricName, float $value, string $operator = '>', int $limit = 100): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('q')
            ->from(Query::class, 'q')
            ->join(Metric::class, 'm', Join::WITH, 'm.query = q')
            ->where('m.name = :metricName')
            ->andWhere("m.value $operator :value")
            ->setParameter('metricName', $metricName)
            ->setParameter('value', $value)
            ->setMaxResults($limit)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}