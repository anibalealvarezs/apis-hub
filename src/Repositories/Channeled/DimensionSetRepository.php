<?php

namespace Repositories\Channeled;

use Entities\Analytics\Channeled\DimensionSet;
use Repositories\BaseRepository;

class DimensionSetRepository extends BaseRepository
{
    public function findOneByHash(string $hash): ?DimensionSet
    {
        return $this->findOneBy(['hash' => $hash]);
    }
}
