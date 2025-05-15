<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ProductRepository extends BaseRepository
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

        return $query->addSelect('p')
            ->addSelect('v')
            ->addSelect('c')
            ->addSelect('pv')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProducts', 'p')
            ->leftJoin('p.channeledVendor', 'v')
            ->leftJoin('p.channeledProductCategories', 'c')
            ->leftJoin('p.channeledProductVariants', 'pv');
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
     * @param string $productId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductId(string $productId): ?Entity
    {
        return $this->createBaseQueryBuilder()
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
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.productId = :productId')
            ->setParameter('productId', $productId)
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
        $entity['channeledProducts'] = array_map(function ($channelProduct) {
            $channelProduct['channel'] = Channels::from($channelProduct['channel'])->getName();
            unset($channelProduct['channeledVendor']['channel']);
            $channelProduct['channeledProductCategories'] = array_map(function ($channeledProductCategory) {
                unset($channeledProductCategory['channel']);
                return $channeledProductCategory;
            }, $channelProduct['channeledProductCategories']);
            $channelProduct['channeledProductVariants'] = array_map(function ($channeledProductVariant) {
                unset($channeledProductVariant['channel']);
                return $channeledProductVariant;
            }, $channelProduct['channeledProductVariants']);
            return $channelProduct;
        }, $entity['channeledProducts']);

        return $entity;
    }
}