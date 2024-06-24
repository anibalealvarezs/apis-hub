<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class ProductRepository extends BaseRepository
{
    /**
     * @param string $productId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductId(string $productId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.productId = :productId')
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $productId
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByProductId(string $productId): bool
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.productId = :productId')
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->addSelect('pv')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledProducts', 'p');
        $query->leftJoin('p.channeledVendor', 'v');
        $query->leftJoin('p.channeledProductCategories', 'c');
        $query->leftJoin('p.channeledProductVariants', 'pv');
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
            $item['channeledProducts'] = array_map(function($channelProduct) {
                $channelProduct['channel'] = Channels::from($channelProduct['channel'])->getName();
                unset($channelProduct['channeledVendor']['channel']);
                $channelProduct['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                    unset($channeledProductCategory['channel']);
                    return $channeledProductCategory;
                }, $channelProduct['channeledProductCategories']);
                $channelProduct['channeledProductVariants'] = array_map(function($channeledProductVariant) {
                    unset($channeledProductVariant['channel']);
                    return $channeledProductVariant;
                }, $channelProduct['channeledProductVariants']);
                return $channelProduct;
            }, $item['channeledProducts']);
            return $item;
        }, $list));
    }
}