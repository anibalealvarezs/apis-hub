<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Enums\Channels;

class PriceRuleRepository extends BaseRepository
{
    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('p')
            ->addSelect('d')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledPriceRules', 'p');
        $query->leftJoin('p.channeledDiscounts', 'd');
        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function($item) {
            $item['channeledPriceRules'] = array_map(function($channelPriceRule) {
                $channelPriceRule['channel'] = Channels::from($channelPriceRule['channel'])->getName();
                $channelPriceRule['channeledDiscounts'] = array_map(function($channeledDiscount) {
                    unset($channeledDiscount['channel']);
                    return $channeledDiscount;
                }, $channelPriceRule['channeledDiscounts']);
                return $channelPriceRule;
            }, $item['channeledPriceRules']);
            return $item;
        }, $list));
    }
}