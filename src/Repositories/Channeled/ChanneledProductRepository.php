<?php

    namespace Repositories\Channeled;

    use Doctrine\ORM\AbstractQuery;
    use Doctrine\ORM\Exception\ORMException;
    use Doctrine\ORM\NonUniqueResultException;
    use Doctrine\ORM\NoResultException;
    use Doctrine\ORM\OptimisticLockException;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Entity;
    use Entities\Analytics\Channel;
    use Enums\QueryBuilderType;
    use Exception;

    class ChanneledProductRepository extends ChanneledBaseRepository
    {
        /**
         * @param QueryBuilderType $type
         * @return QueryBuilder
         * @throws Exception
         */
        protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
        {
            $query = $this->_em->createQueryBuilder();
            match ($type) {
                QueryBuilderType::SELECT => $query->select('e'),
                QueryBuilderType::COUNT => $query->select('count(e.id)'),
                QueryBuilderType::LAST => $query->select('e, LENGTH(e.platformId) AS HIDDEN length'),
                QueryBuilderType::CUSTOM => null,
                QueryBuilderType::AGGREGATE => throw new Exception('To be implemented')
            };

            return $query
                ->addSelect('v')
                ->addSelect('c')
                ->addSelect('pv')
                ->from($this->getEntityName(), 'e')
                ->leftJoin('e.channeledVendor', 'v')
                ->leftJoin('e.channeledProductCategories', 'c')
                ->leftJoin('e.channeledProductVariants', 'pv');
        }

        /**
         * @param string $sku
         * @param mixed $channel
         * @return Entity|null
         * @throws NonUniqueResultException|Exception
         */
        public function getBySku(string $sku, mixed $channel): ?Entity
        {
            $channel = $this->validateChannel($channel);

            return $this->createBaseQueryBuilder()
                ->where('e.sku = :sku')
                ->setParameter('sku', $sku)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->getQuery()
                ->getOneOrNullResult(hydrationMode: AbstractQuery::HYDRATE_OBJECT);
        }

        /**
         * @param string $sku
         * @param mixed $channel
         * @return bool
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function existsBySku(string $sku, mixed $channel): bool
        {
            $channel = $this->validateChannel($channel);

            return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                    ->where('e.sku = :sku')
                    ->setParameter('sku', $sku)
                    ->andWhere('e.channel = :channel')
                    ->setParameter('channel', $channel)
                    ->getQuery()
                    ->getSingleScalarResult() > 0;
        }

        /**
         * @param array $entity
         * @return array
         * @throws ORMException
         * @throws OptimisticLockException
         */
        protected function replaceChannelName(array $entity): array
        {
            if (isset($entity['channel']) && is_numeric($entity['channel'])) {
                $channelEntity = $this->_em->find(Channel::class, $entity['channel']);
                if ($channelEntity) {
                    $entity['channel'] = $channelEntity->getName();
                }
            }

            if (isset($entity['channeledVendor']['channel'])) {
                unset($entity['channeledVendor']['channel']);
            }

            if (isset($entity['channeledProductCategories'])) {
                $entity['channeledProductCategories'] = array_map(function ($channeledProductCategory) {
                    if (isset($channeledProductCategory['channel'])) {
                        unset($channeledProductCategory['channel']);
                    }

                    return $channeledProductCategory;
                }, $entity['channeledProductCategories']);
            }

            if (isset($entity['channeledProductVariants'])) {
                $entity['channeledProductVariants'] = array_map(function ($channeledProductVariant) {
                    if (isset($channeledProductVariant['channel'])) {
                        unset($channeledProductVariant['channel']);
                    }

                    return $channeledProductVariant;
                }, $entity['channeledProductVariants']);
            }

            return $entity;
        }
    }