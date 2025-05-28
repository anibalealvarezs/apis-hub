<?php

namespace Repositories\Channeled;

use Entities\Entity;
use stdClass;

class ChanneledAdGroupRepository extends ChanneledBaseRepository
{
    public function create(?stdClass $data = null, bool $returnEntity = false): array|Entity|null
    {
        if (!$data || !isset($data->platformId) || !isset($data->channel)) {
            return null; // Or throw InvalidArgumentException
        }
        $data->name = $data->name ?? 'Unnamed Ad Group';
        $data->data = $data->data ?? [];
        return parent::create($data, $returnEntity);
    }
}