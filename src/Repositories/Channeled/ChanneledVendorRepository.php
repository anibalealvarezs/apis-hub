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

    class ChanneledVendorRepository extends ChanneledBaseRepository
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
                ->addSelect('p')
                ->from($this->getEntityName(), 'e')
                ->leftJoin('e.channeledProducts', 'p');
        }

        /**
         * @param string $name
         * @param mixed $channel
         * @return Entity|null
         * @throws NonUniqueResultException|Exception
         */
        public function getByName(string $name, mixed $channel): ?Entity
        {
            $channel = $this->validateChannel($channel);

            return $this->createBaseQueryBuilder()
                ->where('e.name = :name')
                ->setParameter('name', $name)
                ->andWhere('e.channel = :channel')
                ->setParameter('channel', $channel)
                ->getQuery()
                ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        }

        /**
         * @param string $name
         * @param mixed $channel
         * @return bool
         * @throws NonUniqueResultException
         * @throws NoResultException
         */
        public function existsByName(string $name, mixed $channel): bool
        {
            $channel = $this->validateChannel($channel);

            return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                    ->where('e.name = :name')
                    ->setParameter('name', $name)
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

            if (isset($entity['channeledProducts'])) {
                $entity['channeledProducts'] = array_map(function ($channeledProduct) {
                    if (isset($channeledProduct['channel'])) {
                        unset($channeledProduct['channel']);
                    }

                    return $channeledProduct;
                }, $entity['channeledProducts']);
            }

            return $entity;
        }
    }