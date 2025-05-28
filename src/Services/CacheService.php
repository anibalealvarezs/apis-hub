<?php

namespace Services;

use __PHP_Incomplete_Class;
use Closure;
use Exception;
use Helpers\Helpers;
use Predis\ClientInterface;

class CacheService
{
    private static ?CacheService $instance = null;
    private ClientInterface $redisClient;

    public function __construct(ClientInterface $redisClient)
    {
        $this->redisClient = $redisClient;
    }

    public static function getInstance(ClientInterface $redisClient): self
    {
        if (self::$instance === null) {
            self::$instance = new self($redisClient);
        }
        return self::$instance;
    }

    /**
     * Get cached data or execute callback to store and return data
     * @param string $key
     * @param Closure $callback
     * @param int $ttl
     * @return mixed
     */
    public function get(string $key, Closure $callback, int $ttl = 3600): mixed
    {
        $cached = $this->redisClient->get($key);
        if ($cached !== null) {
            $value = unserialize($cached, ['allowed_classes' => false]);
            if ($value !== false && !($value instanceof __PHP_Incomplete_Class)) {
                return $value;
            }
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Set data in cache with TTL
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->redisClient->setex($key, $ttl, serialize($value));
    }

    /**
     * Delete a specific cache key
     * @param string $key
     * @return void
     */
    public function delete(string $key): void
    {
        $this->redisClient->del([$key]);
    }

    /**
     * Check if a cache key exists
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        return $this->redisClient->exists($key) > 0;
    }

    /**
     * Delete keys matching a pattern
     * @param string $pattern
     * @return void
     */
    public function deletePattern(string $pattern): void
    {
        $cursor = 0;
        do {
            $scan = $this->redisClient->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
            $cursor = (int) $scan[0];
            $keys = $scan[1];
            if (!empty($keys)) {
                $this->redisClient->del($keys);
            }
        } while ($cursor !== 0);
    }

    /**
     * Invalidate caches for multiple entities and their channeled counterparts
     * @param array $entities Array of [entity => id|null] or [entity => [ids]]
     * @param string|null $channel Optional channel for channeled entities
     * @return void
     */
    public function invalidateMultipleEntities(array $entities, ?string $channel = null): void
    {
        $cacheKeyGenerator = new CacheKeyGenerator();
        $entitiesConfig = Helpers::getEntitiesConfig();
        $channeledEntityMap = [];
        foreach ($entitiesConfig as $config) {
            if (isset($config['channeled_class'])) {
                $nonChanneledClass = $config['class'];
                $channeledClass = $config['channeled_class'];
                $nonChanneledName = basename(str_replace('\\', '/', $nonChanneledClass));
                $channeledName = basename(str_replace('\\', '/', $channeledClass));
                $channeledEntityMap[$nonChanneledName] = array_merge(
                    $channeledEntityMap[$nonChanneledName] ?? [],
                    [$channeledName]
                );
            }
        }

        foreach ($entities as $entity => $idData) {
            $ids = is_array($idData) ? array_filter($idData) : ($idData !== null ? [$idData] : []);

            // Invalidate single-entity caches
            if (!str_starts_with($entity, 'Channeled') && !empty($ids)) {
                $cacheKeys = array_map(
                    fn($id) => $cacheKeyGenerator->forEntity(entityType: $entity, id: $id),
                    $ids
                );
                $this->redisClient->del($cacheKeys);
            }

            // Invalidate list and count caches for both cases
            $entityLower = strtolower($entity);
            $entityUpper = ucfirst($entity);
            $this->deletePattern(pattern: "list_" . $entityLower . "_*");
            $this->deletePattern(pattern: "count_" . $entityLower . "_*");
            $this->deletePattern(pattern: "list_" . $entityUpper . "_*");
            $this->deletePattern(pattern: "count_" . $entityUpper . "_*");

            // Invalidate channeled entity caches
            if (isset($channeledEntityMap[$entity]) && $channel) {
                foreach ($channeledEntityMap[$entity] as $channeledEntity) {
                    if (!empty($entities[$channeledEntity])) {
                        $channeledIds = is_array($entities[$channeledEntity]) ? array_filter($entities[$channeledEntity]) : [$entities[$channeledEntity]];
                        $channeledCacheKeys = array_map(
                            fn($id) => $cacheKeyGenerator->forChanneledEntity(channel: $channel, entityType: $channeledEntity, id: $id),
                            $channeledIds
                        );
                        $this->redisClient->del($channeledCacheKeys);
                    }
                    $channeledEntityLower = strtolower($channeledEntity);
                    $channeledEntityUpper = ucfirst($channeledEntity);
                    $this->deletePattern(pattern: "channeled_list_" . $channeledEntityLower . "_" . $channel . "_*");
                    $this->deletePattern(pattern: "channeled_count_" . $channeledEntityLower . "_" . $channel . "_*");
                    $this->deletePattern(pattern: "channeled_list_" . $channeledEntityUpper . "_" . $channel . "_*");
                    $this->deletePattern(pattern: "channeled_count_" . $channeledEntityUpper . "_" . $channel . "_*");
                }
            }
        }
    }

    /**
     * Invalidate cache entries arbitrarily for an entity.
     *
     * @param string $entity Entity short name (e.g., 'Product')
     * @param array|null $ids Specific entity IDs to delete (null = skip)
     * @param bool $includeListAndCount Whether to remove list/count caches
     * @param string|null $channel Optional channel for channeled entities
     * @return bool
     */
    public function invalidateEntityCache(string $entity, ?array $ids = null, bool $includeListAndCount = true, ?string $channel = null): bool
    {
        try {
            $cacheKeyGenerator = new CacheKeyGenerator();

            // Invalidate specific IDs
            if ($ids !== null) {
                $ids = array_filter($ids);
                if (!empty($ids)) {
                    $keys = array_map(fn($id) => $cacheKeyGenerator->forEntity($entity, $id), $ids);
                    $this->redisClient->del($keys);
                }
            }

            // Invalidate list/count keys
            if ($includeListAndCount) {
                $this->deletePattern("list_" . $entity . "_*");
                $this->deletePattern("count_" . $entity . "_*");
            }

            // Invalidate channeled keys if channel is given
            if ($channel) {
                if (!empty($ids)) {
                    $channeledKeys = array_map(
                        fn($id) => $cacheKeyGenerator->forChanneledEntity($channel, $entity, $id),
                        $ids
                    );
                    $this->redisClient->del($channeledKeys);
                }

                if ($includeListAndCount) {
                    $this->deletePattern("channeled_list_" . $entity . "_" . $channel . "_*");
                    $this->deletePattern("channeled_count_" . $entity . "_" . $channel . "_*");
                }
            }

            return true;
        } catch (Exception $e) {
            // Handle exceptions (e.g., log them)
            return false;
        }
    }

    public function deleteByPrefix(string $prefix): void
    {
        $keys = $this->redisClient->keys($prefix . '*');
        foreach ($keys as $key) {
            $this->redisClient->del($key);
        }
    }
}