<?php

namespace Services;

use DateTimeImmutable;
use DateTimeInterface;
use Helpers\Helpers;
use Predis\ClientInterface;

class CacheStrategyService
{
    private const TTL_HISTORICAL = 604800; // 7 days in seconds
    private const TTL_RECENT = 86400; // 24 hours in seconds
    private const RECENT_THRESHOLD = '-3 days'; // Matches InstanceGeneratorService

    /**
     * @return ClientInterface
     */
    private static function getRedis(): ClientInterface
    {
        return Helpers::getRedisClient();
    }

    /**
     * Determine if an aggregation request should be cached based on channel toggle.
     * 
     * @param string $channelKey
     * @return bool
     */
    public static function isCacheable(string $channelKey): bool
    {
        try {
            $config = \Classes\DriverInitializer::validateConfig($channelKey);
            return (bool) ($config['cache_aggregations'] ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns 'historical' or 'recent' based on the request's end date.
     * 
     * @param DateTimeInterface|string $endDate
     * @return string
     */
    public static function getTargetCacheType(DateTimeInterface|string $endDate): string
    {
        try {
            $end = ($endDate instanceof DateTimeInterface) ? $endDate : new DateTimeImmutable($endDate);
            $threshold = new DateTimeImmutable('today ' . self::RECENT_THRESHOLD);
            return ($end < $threshold) ? 'historical' : 'recent';
        } catch (\Exception $e) {
            return 'recent'; // Default to safer/shorter TTL if date parsing fails
        }
    }

    /**
     * Get cached aggregation data.
     * 
     * @param string $key
     * @param string $type
     * @return array|null
     */
    public static function get(string $key, string $type = 'historical'): ?array
    {
        $redis = self::getRedis();
        $data = $redis->get($key);
        if ($data) {
            // Sliding window: refresh TTL on access
            $ttl = ($type === 'recent') ? self::TTL_RECENT : self::TTL_HISTORICAL;
            $redis->expire($key, $ttl);
            return json_decode($data, true);
        }
        return null;
    }

    /**
     * Set aggregation data in cache.
     * 
     * @param string $key
     * @param array $data
     * @param string $type
     */
    public static function set(string $key, array $data, string $type = 'historical'): void
    {
        $ttl = ($type === 'recent') ? self::TTL_RECENT : self::TTL_HISTORICAL;
        self::getRedis()->setex($key, $ttl, json_encode($data));
    }

    /**
     * Clear all aggregation cache for a specific channel.
     * 
     * @param string $channelKey
     */
    public static function clearChannel(string $channelKey): void
    {
        $redis = self::getRedis();
        $pattern = "agg:{$channelKey}:*";
        $keys = $redis->keys($pattern);
        if (!empty($keys)) {
            $redis->del($keys);
        }
    }

    /**
     * Clear only recent aggregation cache for a specific channel.
     * Usually called after a sync job finishes.
     * 
     * @param string $channelKey
     */
    public static function clearRecent(string $channelKey): void
    {
        $redis = self::getRedis();
        $pattern = "agg:{$channelKey}:recent:*";
        $keys = $redis->keys($pattern);
        if (!empty($keys)) {
            $redis->del($keys);
        }
    }

    /**
     * Generate a unique cache key based on parameters and cache type.
     * 
     * @param string $channelKey
     * @param array $params
     * @param string $type
     * @return string
     */
    public static function generateKey(string $channelKey, array $params, string $type = 'historical'): string
    {
        ksort($params);
        $hash = md5(serialize($params));
        return "agg:{$channelKey}:{$type}:{$hash}";
    }
}
