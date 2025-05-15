<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;

class ProductCategoryRepository extends BaseRepository
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
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledProductCategories', 'p');
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
     * @param string $productCategoryId
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByProductCategoryId(string $productCategoryId): ?Entity
    {
        return $this->createBaseQueryBuilder()
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
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.productCategoryId = :productCategoryId')
            ->setParameter('productCategoryId', $productCategoryId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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