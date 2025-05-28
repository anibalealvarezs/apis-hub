<?php

namespace Repositories\Channeled;

use Doctrine\ORM\QueryBuilder;
use Enums\Channel;
use Enums\QueryBuilderType;
use Exception;
use RuntimeException;

class ChanneledPriceRuleRepository extends ChanneledBaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        if (!$this->_em) {
            throw new RuntimeException("EntityManager is not initialized");
        }
        $query = $this->_em->createQueryBuilder();
        if (!$query) {
            throw new RuntimeException("Failed to create QueryBuilder");
        }
        match ($type) {
            QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::LAST => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
            QueryBuilderType::CUSTOM => null,
        };
        return $query
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledDiscounts', 'd');
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        if (isset($entity['channel'])) {
            $entity['channel'] = Channel::from($entity['channel'])->getName();
        }
        if (isset($entity['channeledDiscounts'])) {
            $entity['channeledDiscounts'] = array_map(function($channeledDiscount) {
                unset($channeledDiscount['channel']);
                return $channeledDiscount;
            }, $entity['channeledDiscounts']);
        }
        return $entity;
    }
}
