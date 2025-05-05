<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Entities\Entity;
use Enums\Channels;

class PriceRuleRepository extends BaseRepository
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
            ->addSelect('d')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledPriceRules', 'p')
            ->leftJoin('p.channeledDiscounts', 'd')
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
            ->addSelect('d')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledPriceRules', 'p');
        $query->leftJoin('p.channeledDiscounts', 'd');
        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }
        if ($filters) {
            foreach($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
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
        $entity['channeledPriceRules'] = array_map(function($channelPriceRule) {
            $channelPriceRule['channel'] = Channels::from($channelPriceRule['channel'])->getName();
            $channelPriceRule['channeledDiscounts'] = array_map(function($channeledDiscount) {
                unset($channeledDiscount['channel']);
                return $channeledDiscount;
            }, $channelPriceRule['channeledDiscounts']);
            return $channelPriceRule;
        }, $entity['channeledPriceRules']);
        return $entity;
    }
}