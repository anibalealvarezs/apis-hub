<?php

namespace Services;

class CacheKeyGenerator
{
    public function forEntity(string $entityType, int|string $id): string
    {
        return 'entity:' . $entityType . ':' . $id;
    }

    public function forChanneledEntity(string|int $channel, string $entityType, int|string $id): string
    {
        return 'entity:' . $channel . ':' . $entityType . ':' . $id;
    }
}
