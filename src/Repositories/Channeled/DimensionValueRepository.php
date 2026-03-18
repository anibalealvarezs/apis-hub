<?php

namespace Repositories\Channeled;

use Entities\Analytics\Channeled\DimensionKey;
use Entities\Analytics\Channeled\DimensionValue;
use Repositories\BaseRepository;

class DimensionValueRepository extends BaseRepository
{
    public function findOneByKeyValue(DimensionKey $key, string $value): ?DimensionValue
    {
        return $this->findOneBy([
            'dimensionKey' => $key,
            'value' => $value
        ]);
    }
}
