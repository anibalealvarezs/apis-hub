<?php

namespace Repositories\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Entities\Entity;
use Enums\Channels;
use ReflectionEnum;

class ChanneledProductRepository extends ChanneledBaseRepository
{
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
            ->addSelect('v')
            ->addSelect('c')
            ->addSelect('pv')
            ->from($this->getEntityName(), 'e');
        $query->leftJoin('e.channeledVendor', 'v');
        $query->leftJoin('e.channeledProductCategories', 'c');
        $query->leftJoin('e.channeledProductVariants', 'pv');
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
            $item['channel'] = Channels::from($item['channel'])->getName();
            unset($item['channeledVendor']['channel']);
            $item['channeledProductCategories'] = array_map(function($channeledProductCategory) {
                unset($channeledProductCategory['channel']);
                return $channeledProductCategory;
            }, $item['channeledProductCategories']);
            $item['channeledProductVariants'] = array_map(function($channeledProductVariant) {
                unset($channeledProductVariant['channel']);
                return $channeledProductVariant;
            }, $item['channeledProductVariants']);
            return $item;
        }, $list));
    }

    /**
     * @param string $sku
     * @param int $channel
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getBySku(string $sku, int $channel): ?Entity
    {
        if ((new ReflectionEnum(objectOrClass: Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityName(), 'e')
            ->where('e.sku = :sku')
            ->setParameter('sku', $sku)
            ->andWhere('e.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getOneOrNullResult(hydrationMode: AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $sku
     * @param int $channel
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsBySku(string $sku, int $channel): bool
    {
        if ((new ReflectionEnum(objectOrClass: Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return $this->_em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from($this->getEntityName(), 'e')
                ->where('e.sku = :sku')
                ->setParameter('sku', $sku)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }
}
