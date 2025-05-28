<?php

namespace Repositories\Channeled;

use Entities\Entity;
use stdClass;

class ChanneledCampaignRepository extends ChanneledBaseRepository
{
    public function create(?stdClass $data = null, bool $returnEntity = false): array|Entity|null
    {
        if (!$data || !isset($data->platformId) || !isset($data->channel)) {
            return null; // Or throw InvalidArgumentException
        }
        $data->data = $data->data ?? [];
        return parent::create($data, $returnEntity);
    }
}