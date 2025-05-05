<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class ProductCategoryRepository extends BaseRepository
{
    /**
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     */
    public function read(int $id, bool $returnEntity = false, object $filters = null): Entity|array|null
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('p')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProductCategories', 'p')
            ->where('e.id = :id')
            ->setParameter('id', $id);
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        if ($returnEntity) {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        } else {
            $entity = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        }

        if (!$entity) {
            return null;
        }

        if (is_array($entity)) {
            $entity = $this->replaceChannelName($entity);
        }

        return $entity;
    }

    /**
     * @param string $productCategoryId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductCategoryId(string $productCategoryId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.productCategoryId = :productCategoryId')
            ->setParameter('productCategoryId', $productCategoryId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $productCategoryId
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByProductCategoryId(string $productCategoryId): bool
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.productCategoryId = :productCategoryId')
            ->setParameter('productCategoryId', $productCategoryId)
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
    public function readMultiple(int $limit = 100, int $pagination = 0, ?array $ids = null, object $filters = null): ArrayCollection
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->addSelect('p')
            ->from($this->getEntityName(), 'e');
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
            return $this->replaceChannelName($item);
        }, $list));
    }

    private function replaceChannelName(array $entity): array
    {
        $entity['channeledProductCategories'] = array_map(function($channeledProductCategory) {
            $channeledProductCategory['channel'] = Channels::from($channeledProductCategory['channel'])->getName();
            return $channeledProductCategory;
        }, $entity['channeledProductCategories']);
        return $entity;
    }
}