<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Enums\Channels;

class ChanneledPriceRuleRepository extends ChanneledBaseRepository
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
            ->addSelect('d')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledDiscounts', 'd');
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
            $item['channel'] = Channels::from($item['channel'])->getName();
            $item['channeledDiscounts'] = array_map(function($channeledDiscount) {
                unset($channeledDiscount['channel']);
                return $channeledDiscount;
            }, $item['channeledDiscounts']);
            return $item;
        }, $list));
    }
}
