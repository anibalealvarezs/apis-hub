<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\Channels;

class ProductCategoryRepository extends BaseRepository
{
    /**
     * @param string $productCategoryId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductCategoryId(string $productCategoryId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.productCategoryId = :productCategoryId')
            ->setParameter('productCategoryId', $productCategoryId)
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
            ->addSelect('p')
            ->from($this->_entityName, 'e');
        $query->leftJoin('e.channeledProductCategories', 'p');
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
            $item['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                $channeledProductCategory['channel'] = Channels::from($channeledProductCategory['channel'])->getName();
                return $channeledProductCategory;
            }, $item['channeledProductCategories']);
            return $item;
        }, $list));
    }
}