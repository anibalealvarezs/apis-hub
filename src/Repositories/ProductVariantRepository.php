<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\Channels;

class ProductVariantRepository extends BaseRepository
{
    /**
     * @param string $productVariantId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductVariantId(string $productVariantId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.productVariantId = :productVariantId')
            ->setParameter('productVariantId', $productVariantId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @return ArrayCollection
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('pv')
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->_entityName, 'e');
        $query->leftJoin('e.channeledProductVariants', 'pv');
        $query->leftJoin('pv.channeledProduct', 'p');
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
            $item['channeledProductVariants'] = array_map(function($channelProductVariant) {
                $channelProductVariant['channel'] = Channels::from($channelProductVariant['channel'])->getName();
                unset($channelProductVariant['channeledProduct']['channel']);
                unset($channelProductVariant['channeledProduct']['channeledVendor']['channel']);
                $channelProductVariant['channeledProduct']['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                    unset($channeledProductCategory['channel']);
                    return $channeledProductCategory;
                }, $channelProductVariant['channeledProduct']['channeledProductCategories']);
                return $channelProductVariant;
            }, $item['channeledProductVariants']);
            return $item;
        }, $list));
    }
}