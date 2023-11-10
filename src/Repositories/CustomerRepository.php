<?php

namespace Repositories;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Customer;
use Enums\Channels;
use ReflectionEnum;
use ReflectionException;

class CustomerRepository extends BaseRepository
{
    /**
     * @param int $platformId
     * @param int $channel
     * @return array|null
     * @throws NonUniqueResultException
     */
    public function getByPlatformIdAndChannel(int $platformId, int $channel): ?Customer
    {
        if ((new ReflectionEnum(Channels::class))->getConstant($channel)) {
            die ('Invalid channel');
        }

        return parent::getByPlatformIdAndChannel(platformId: $platformId, channel: $channel);
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param bool $withAssociations
     * @return array
     * @throws MappingException
     * @throws ReflectionException
     */
    public function readMultiple(int $limit = 10, int $pagination = 0, object $filters = null, bool $withAssociations = false): array
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from($this->_entityName, 'e');
        foreach($filters as $key => $value) {
            if ($key == 'channel' && !is_int($value)) {
                $value = (new ReflectionEnum(Channels::class))->getConstant($value);
            }
            $query->andWhere('e.' . $key . ' = :' . $key)
                ->setParameter($key, $value);
        }
        $list = $query->setMaxResults($limit)
            ->setFirstResult($limit * $pagination)
            ->getQuery()
            ->getResult();

        return array_map(function ($element) use ($withAssociations) {
            return $this->mapEntityData($element, $withAssociations);
        }, $list);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     */
    protected function mapEntityData(object $entity, bool $withAssociations = false): array
    {
        $data = parent::mapEntityData($entity, $withAssociations);
        $data['channel'] = $this->getChannelName($data['channel']);

        return $data;
    }

    /**
     * @param int $channel
     * @return string
     */
    public function getChannelName(int $channel): string
    {
        return Channels::from($channel)->getName();
    }
}
