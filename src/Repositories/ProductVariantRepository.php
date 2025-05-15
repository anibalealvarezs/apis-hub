<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ProductVariantRepository extends BaseRepository
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

        return $query->addSelect('pv')
            ->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProductVariants', 'pv')
            ->leftJoin('pv.channeledProduct', 'p')
            ->leftJoin('p.channeledVendor', 'v')
            ->leftJoin('p.channeledProductCategories', 'c');
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        return $this->replaceChannelName($result);
    }

    /**
     * @param string $productVariantId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductVariantId(string $productVariantId): ?Entity
    {
        return $this->createBaseQueryBuilder()
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
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
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
        return $this->createBaseQueryBuilder()
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
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.sku = :sku')
                ->setParameter('sku', $sku)
                ->getQuery()
                ->getSingleScalarResult() > 0;
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