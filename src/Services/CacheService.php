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

    private function __construct(ClientInterface $redisClient)
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
            try {
                $value = unserialize($cached, ['allowed_classes' => false]);
                if ($value !== false && !($value instanceof __PHP_Incomplete_Class)) {
                    return $value;
                }
                error_log("Unserialize returned false or incomplete class for key: $key, data: " . var_export($cached, true));
            } catch (Exception $e) {
                error_log("Unserialize error for key: $key, message: " . $e->getMessage() . ", data: " . var_export($cached, true));
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
        try {
            $this->redisClient->setex($key, $ttl, serialize($value));
        } catch (Exception $e) {
            error_log("Failed to set cache for key: $key, message: " . $e->getMessage());
        }
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
        // Build dynamic channeled entity map from entitiesconfig.yaml
        $entitiesConfig = Helpers::getEntitiesConfig();
        $channeledEntityMap = [];
        foreach ($entitiesConfig as $entityKey => $config) {
            if (isset($config['channeled_class'])) {
                $nonChanneledClass = $config['class'];
                $channeledClass = $config['channeled_class'];
                // Extract short names (e.g., 'Customer' from 'Entities\Analytics\Customer')
                $nonChanneledName = basename(str_replace('\\', '/', $nonChanneledClass));
                $channeledName = basename(str_replace('\\', '/', $channeledClass));
                $channeledEntityMap[$nonChanneledName] = array_merge(
                    $channeledEntityMap[$nonChanneledName] ?? [],
                    [$channeledName]
                );
            }
        }

        foreach ($entities as $entity => $idData) {
            // Handle single ID or array of IDs
            $ids = is_array($idData) ? array_filter($idData) : ($idData !== null ? [$idData] : []);

            // Invalidate single-entity caches (skip for Channeled entities to avoid redundancy)
            if (!str_starts_with($entity, 'Channeled') && !empty($ids)) {
                $cacheKeys = array_map(
                    fn($id) => $cacheKeyGenerator->forEntity(entityType: $entity, id: $id),
                    $ids
                );
                $this->redisClient->del($cacheKeys);
            }

            // Invalidate list and count caches
            $this->deletePattern(pattern: "list_{$entity}_*");
            $this->deletePattern(pattern: "count_{$entity}_*");

            // Invalidate channeled entity caches
            if (isset($channeledEntityMap[$entity]) && $channel) {
                foreach ($channeledEntityMap[$entity] as $channeledEntity) {
                    // Only invalidate channeled single-entity caches if the entity is explicitly listed
                    if (!empty($entities[$channeledEntity])) {
                        $channeledIds = is_array($entities[$channeledEntity]) ? array_filter($entities[$channeledEntity]) : [$entities[$channeledEntity]];
                        $channeledCacheKeys = array_map(
                            fn($id) => $cacheKeyGenerator->forChanneledEntity(channel: $channel, entityType: $channeledEntity, id: $id),
                            $channeledIds
                        );
                        $this->redisClient->del($channeledCacheKeys);
                    }
                    $this->deletePattern(pattern: "channeled_list_{$channeledEntity}_{$channel}_*");
                    $this->deletePattern(pattern: "channeled_count_{$channeledEntity}_{$channel}_*");
                }
            }
        }
    }
}