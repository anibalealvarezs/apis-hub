<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\Channels;
use ReflectionEnum;

class DiscountRepository extends BaseRepository
{
    /**
     * @param string $code
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByCode(string $code): ?Entity
    {
        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e')
            ->where('e.code = :code')
            ->setParameter('code', $code)
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
            ->addSelect('c')
            ->addSelect('p')
            ->from($this->_entityName, 'e');
        $query->leftJoin('e.channeledDiscounts', 'c');
        $query->leftJoin('c.channeledPriceRule', 'p');
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
            $item['channeledDiscounts'] = array_map(function($channelDiscount) {
                $channelDiscount['channel'] = Channels::from($channelDiscount['channel'])->getName();
                unset($channelDiscount['channeledPriceRule']['channel']);
                return $channelDiscount;
            }, $item['channeledDiscounts']);
            return $item;
        }, $list));
    }
}