<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Enums\Channels;

class ChanneledProductVariantRepository extends ChanneledBaseRepository
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
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledProduct', 'p');
        $query->leftJoin('p.channeledVendor', 'v');
        $query->leftJoin('p.channeledProductCategories', 'c');
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
            unset($item['channeledProduct']['channel']);
            unset($item['channeledProduct']['channeledVendor']['channel']);
            $item['channeledProduct']['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                unset($channeledProductCategory['channel']);
                return $channeledProductCategory;
            }, $item['channeledProduct']['channeledProductCategories']);
            return $item;
        }, $list));
    }
}
