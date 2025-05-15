<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class PriceRuleRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
        };

        return $query->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledPriceRules', 'p')
            ->leftJoin('p.channeledDiscounts', 'd');
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        return $this->replaceChannelName($result);
    }

    private function replaceChannelName(array $entity): array
    {
        $entity['channeledPriceRules'] = array_map(function($channelPriceRule) {
            $channelPriceRule['channel'] = Channels::from($channelPriceRule['channel'])->getName();
            $channelPriceRule['channeledDiscounts'] = array_map(function($channeledDiscount) {
                unset($channeledDiscount['channel']);
                return $channeledDiscount;
            }, $channelPriceRule['channeledDiscounts']);
            return $channelPriceRule;
        }, $entity['channeledPriceRules']);
        return $entity;
    }
}