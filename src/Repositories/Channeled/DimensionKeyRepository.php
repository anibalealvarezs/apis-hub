<?php

namespace Repositories\Channeled;

use Entities\Analytics\Channeled\DimensionKey;
use Repositories\BaseRepository;

class DimensionKeyRepository extends BaseRepository
{
    public function findOneByName(string $name): ?DimensionKey
    {
        return $this->findOneBy(['name' => $name]);
    }
}
