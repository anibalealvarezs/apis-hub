<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Enums\Channels;

class ChanneledProductRepository extends ChanneledBaseRepository
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
            ->addSelect('v')
            ->addSelect('c')
            ->addSelect('pv')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledVendor', 'v');
        $query->leftJoin('e.channeledProductCategories', 'c');
        $query->leftJoin('e.channeledProductVariants', 'pv');
        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }
        foreach($filters as $key => $value) {
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return new ArrayCollection(array_map(function($item) {
            $item['channel'] = Channels::from($item['channel'])->getName();
            unset($item['channeledVendor']['channel']);
            $item['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                unset($channeledProductCategory['channel']);
                return $channeledProductCategory;
            }, $item['channeledProductCategories']);
            $item['channeledProductVariants'] = array_map(function($channeledProductVariant) {
                unset($channeledProductVariant['channel']);
                return $channeledProductVariant;
            }, $item['channeledProductVariants']);
            return $item;
        }, $list));
    }
}
