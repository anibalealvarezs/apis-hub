<?php

    namespace Repositories\Channeled;

    use Doctrine\ORM\Exception\ORMException;
    use Doctrine\ORM\OptimisticLockException;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Analytics\Channel;
    use Enums\QueryBuilderType;
    use Exception;

    class ChanneledOrderRepository extends ChanneledBaseRepository
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
                ->addSelect('c')
                ->addSelect('p')
                ->addSelect('d')
                ->from($this->getEntityName(), 'e')
                ->leftJoin('e.channeledCustomer', 'c')
                ->leftJoin('e.channeledProducts', 'p')
                ->leftJoin('e.channeledDiscounts', 'd');
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

            if (isset($entity['channeledCustomer'])) {
                unset($entity['channeledCustomer']['channel']);
            }

            if (isset($entity['channeledProducts'])) {
                $entity['channeledProducts'] = array_map(function ($channeledProduct) {
                    if (isset($channeledProduct['channel']) && is_numeric($channeledProduct['channel'])) {
                        $channelEntity = $this->_em->find(Channel::class, $channeledProduct['channel']);
                        if ($channelEntity) {
                            $channeledProduct['channel'] = $channelEntity->getName();
                        }
                    }

                    return $channeledProduct;
                }, $entity['channeledProducts']);
            }

            if (isset($entity['channeledDiscounts'])) {
                $entity['channeledDiscounts'] = array_map(function ($channeledDiscount) {
                    if (isset($channeledDiscount['channel']) && is_numeric($channeledDiscount['channel'])) {
                        $channelEntity = $this->_em->find(Channel::class, $channeledDiscount['channel']);
                        if ($channelEntity) {
                            $channeledDiscount['channel'] = $channelEntity->getName();
                        }
                    }

                    return $channeledDiscount;
                }, $entity['channeledDiscounts']);
            }

            return $entity;
        }
    }