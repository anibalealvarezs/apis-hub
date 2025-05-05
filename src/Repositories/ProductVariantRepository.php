<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;

class ProductVariantRepository extends BaseRepository
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
            ->addSelect('pv')
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProductVariants', 'pv')
            ->leftJoin('pv.channeledProduct', 'p')
            ->leftJoin('p.channeledVendor', 'v')
            ->leftJoin('p.channeledProductCategories', 'c')
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
     * @param string $productVariantId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductVariantId(string $productVariantId): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.productVariantId = :productVariantId')
            ->setParameter('productVariantId', $productVariantId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $productVariantId
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByProductVariantId(string $productVariantId): bool
    {
        return $this->_em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($this->getEntityName(), 'e')
            ->where('e.productVariantId = :productVariantId')
            ->setParameter('productVariantId', $productVariantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @param string $sku
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getBySku(string $sku): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.sku = :sku')
            ->setParameter('sku', $sku)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $sku
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsBySku(string $sku): bool
    {
        return $this->_em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from($this->getEntityName(), 'e')
                ->where('e.sku = :sku')
                ->setParameter('sku', $sku)
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
            ->addSelect('pv')
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->getEntityName(), 'e');
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
            return $this->replaceChannelName($item);
        }, $list));
    }

    /**
     * @param mixed $entity
     * @return mixed
     */
    protected function replaceChannelName(mixed $entity): mixed
    {
        $entity['channeledProductVariants'] = array_map(function ($channelProductVariant) {
            $channelProductVariant['channel'] = Channels::from($channelProductVariant['channel'])->getName();
            unset($channelProductVariant['channeledProduct']['channel']);
            unset($channelProductVariant['channeledProduct']['channeledVendor']['channel']);
            $channelProductVariant['channeledProduct']['channeledProductCategories'] = array_map(function (
                $channeledProductCategory
            ) {
                unset($channeledProductCategory['channel']);
                return $channeledProductCategory;
            }, $channelProductVariant['channeledProduct']['channeledProductCategories']);
            return $channelProductVariant;
        }, $entity['channeledProductVariants']);

        return $entity;
    }
}