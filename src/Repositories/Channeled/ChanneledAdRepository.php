<?php

namespace Repositories\Channeled;

use Entities\Entity;

class ChanneledAdRepository extends ChanneledBaseRepository
{
    /**
     * @param object{platformId: string|int, channel: string|int, name?: string, data?: array}|null $data
     * @param bool $returnEntity
     * @return array|Entity|null
     */
    public function create(?object $data = null, bool $returnEntity = false): array|Entity|null
    {
        $data = (array) ($data ?? []);
        if (!isset($data['platformId']) || !isset($data['channel'])) {
            return null; // Or throw InvalidArgumentException
        }
        $data['name'] = $data['name'] ?? 'Unnamed Ad';
        $data['data'] = $data['data'] ?? [];
        return parent::create((object) $data, $returnEntity);
    }
}
