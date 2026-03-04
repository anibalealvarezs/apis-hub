<?php

namespace Repositories\Channeled;

use Entities\Entity;


class ChanneledCampaignRepository extends ChanneledBaseRepository
{
    /**
     * @param object{platformId: string|int, channel: string|int, data?: array}|null $data
     * @param bool $returnEntity
     * @return array|Entity|null
     */
    public function create(?object $data = null, bool $returnEntity = false): array|Entity|null
    {
        $data = (array) ($data ?? []);
        if (!isset($data['platformId']) || !isset($data['channel'])) {
            return null; // Or throw InvalidArgumentException
        }
        $data['data'] = $data['data'] ?? [];
        return parent::create((object) $data, $returnEntity);
    }
}