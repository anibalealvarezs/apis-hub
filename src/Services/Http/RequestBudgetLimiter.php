<?php

declare(strict_types=1);

namespace Services\Http;

use Predis\ClientInterface;

/** Both budgets and the concurrency lease are acquired in one Redis operation. */
final class RequestBudgetLimiter
{
    private const ACQUIRE = <<<'LUA'
local clock = redis.call('TIME')
local now = tonumber(clock[1]) + tonumber(clock[2]) / 1000000
local cost, tenantRate, tenantBurst = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])
local actorRate, actorBurst = tonumber(ARGV[4]), tonumber(ARGV[5])
local limit, lease = tonumber(ARGV[6]), tonumber(ARGV[7])
local function count(field)
    redis.call('HINCRBY', KEYS[4], field, 1)
    redis.call('EXPIRE', KEYS[4], 86400)
end
local function balance(key, rate, burst)
    local value = redis.call('HMGET', key, 'tokens', 'at')
    if not value[1] then return burst end
    return math.min(burst, tonumber(value[1]) + math.max(0, now - tonumber(value[2])) * rate / 60)
end
local tenant = balance(KEYS[1], tenantRate, tenantBurst)
local actor = balance(KEYS[2], actorRate, actorBurst)
local wait = math.max(0, (cost - tenant) * 60 / tenantRate, (cost - actor) * 60 / actorRate)
if wait > 0 then count('budget_rejected'); return {0, math.max(1, math.ceil(wait)), 'budget'} end
if limit > 0 then
    redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', now)
    if redis.call('ZCARD', KEYS[3]) >= limit then count('concurrency_rejected'); return {0, 1, 'concurrency'} end
end
redis.call('HSET', KEYS[1], 'tokens', tenant - cost, 'at', now)
redis.call('HSET', KEYS[2], 'tokens', actor - cost, 'at', now)
redis.call('EXPIRE', KEYS[1], math.ceil(tenantBurst * 60 / tenantRate) + 60)
redis.call('EXPIRE', KEYS[2], math.ceil(actorBurst * 60 / actorRate) + 60)
if limit > 0 then
    redis.call('ZADD', KEYS[3], now + lease, ARGV[8])
    redis.call('EXPIRE', KEYS[3], lease + 1)
end
count('admitted')
return {1, 0, 'allowed'}
LUA;

    public function __construct(private readonly ClientInterface $redis, private readonly string $namespace = 'api_resource_guard') {}

    public function acquire(array $policy, string $actor): array
    {
        $prefix = $this->namespace.':{'.$policy['group'].'}';
        $lease = bin2hex(random_bytes(16));
        $result = $this->redis->eval(self::ACQUIRE, 4, $prefix.':tenant', $prefix.':actor:'.hash('sha256', $actor), $prefix.':active', $prefix.':stats',
            $policy['cost'], $policy['tenant_rate'], max($policy['cost'], $policy['tenant_burst']),
            $policy['actor_rate'], max($policy['cost'], $policy['actor_burst']),
            $policy['concurrency'], $policy['lease_seconds'], $lease);
        return ['allowed' => (int)$result[0] === 1, 'retry_after' => (int)$result[1], 'reason' => $result[2],
            'lease' => (int)$result[0] === 1 && $policy['concurrency'] > 0 ? [$prefix.':active', $lease] : null];
    }

    public function release(?array $lease): void
    {
        if ($lease !== null) $this->redis->zrem($lease[0], $lease[1]);
    }
}
