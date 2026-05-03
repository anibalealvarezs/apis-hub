<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Account;
use Entities\Analytics\Channel;
use Enums\QueryBuilderType;

class AccountRepository extends BaseRepository
{
    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws \Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::CUSTOM => throw new \Exception('To be implemented'),
        };

        return $query->addSelect('a')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledAccounts', 'a');
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->replaceChannelName($result);
        return parent::processResult($result);
    }

    /**
     * @param string $name
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByName(string $name): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $name
     * @return bool
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function existsByName(string $name): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function replaceChannelName(array $entity): array
    {
        $entity['channeledAccounts'] = array_map(function ($channelAccount) {
            $channelAccount['channel'] = Channel::from($channelAccount['channel'])->getName();
            return $channelAccount;
        }, $entity['channeledAccounts']);
        return $entity;
    }
}
