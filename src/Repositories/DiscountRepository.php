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

class DiscountRepository extends BaseRepository
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

        return $query->addSelect('d')
            ->addSelect('pr')
            // ->addSelect('o')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledDiscounts', 'd')
            ->leftJoin('d.channeledPriceRule', 'pr');
            // ->leftJoin('d.channeledOrders', 'o');
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
     * @param string $code
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $code
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByCode(string $code): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.code = :code')
                ->setParameter('code', $code)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channeledDiscounts'] = array_map(function ($channelDiscount) {
            $channelDiscount['channel'] = Channels::from($channelDiscount['channel'])->getName();
            unset($channelDiscount['channeledPriceRule']['channel']);
            /* $channelDiscount['channeledOrders'] = array_map(function ($channeledOrder) {
                unset($channeledOrder['channel']);
                return $channeledOrder;
            }, $channelDiscount['channeledOrders']); */
            return $channelDiscount;
        }, $entity['channeledDiscounts']);
        return $entity;
    }
}