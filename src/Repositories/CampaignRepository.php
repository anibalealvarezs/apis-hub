<?php

namespace Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channel;
use Enums\QueryBuilderType;
class CampaignRepository extends BaseRepository
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
            QueryBuilderType::CUSTOM => null,
        };

        return $query->addSelect('c')
            ->addSelect('ag')
            ->from($this->getEntityName(), 'e')
            ->leftJoin('e.channeledCampaigns', 'c')
            ->leftJoin('e.channeledAdGroups', 'ag');
    }

    /**
     * @param object|null $data
     * @phpstan-param object{campaignId?: string, name?: string}|null $data
     * @param bool $returnEntity
     * @return array|Entity|null
     */
    public function create(?object $data = null, bool $returnEntity = false): array|Entity|null
    {
        $data = (array) ($data ?? []);
        if (empty($data['campaignId'])) {
            return null; // Or throw \InvalidArgumentException
        }
        $data['name'] = $data['name'] ?? 'Unnamed Campaign';
        return parent::create((object) $data, $returnEntity);
    }

    /**
     * @param string $campaignId
     * @return Entity|null
     * @throws NonUniqueResultException
     */
    public function getByEmail(string $campaignId /*, bool $useCached = false */): ?Entity
    {
        return $this->createBaseQueryBuilder()
            ->where('e.campaignId = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
    }

    /**
     * @param string $campaignId
     * @return bool
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function existsByEmail(string $campaignId): bool
    {
        return $this->createBaseQueryBuilderNoJoins(QueryBuilderType::COUNT)
                ->where('e.campaignId = :campaignId')
                ->setParameter('campaignId', $campaignId)
                ->getQuery()
                ->getSingleScalarResult() > 0;
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
     * @param array $entity
     * @return array
     */
    protected function replaceChannelName(array $entity): array
    {
        $entity['channeledCampaigns'] = array_map(function($channelCustomer) {
            $channelCustomer['channel'] = Channel::from($channelCustomer['channel'])->getName();
            return $channelCustomer;
        }, $entity['channeledCampaigns']);
        $entity['channeledAdGroups'] = array_map(function($channelCustomer) {
            $channelCustomer['channel'] = Channel::from($channelCustomer['channel'])->getName();
            return $channelCustomer;
        }, $entity['channeledAdGroups']);
        return $entity;
    }
}