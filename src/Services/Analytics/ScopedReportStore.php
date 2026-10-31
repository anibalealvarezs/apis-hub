<?php

namespace Services\Analytics;

/** Durable, tenant-local report queue/cache. No provider calls occur on HTTP reads. */
final class ScopedReportStore
{
    public function __construct(private string $root, private $clock = null)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new \RuntimeException('Report storage is unavailable.');
        $this->root = realpath($root);
        $this->clock ??= static fn () => time();
    }

    public function now(): int { return ($this->clock)(); }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) return null;
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Explicit refresh is bounded; repeated UI clicks cannot bypass source budgets. */
    public function expireReady(string $key, int $minimumAge = 300): void
    {
        $this->locked(function () use ($key, $minimumAge) {
            $record = $this->get($key);
            if (($record['state'] ?? '') === 'ready' && ($record['updated_at'] ?? 0) <= $this->now() - $minimumAge) {
                $record['expires_at'] = 0;
                $this->put($key, $record);
            }
        });
    }

    public function enqueue(string $key, array $request, int $ttl = 3600): array
    {
        return $this->locked(function () use ($key, $request, $ttl) {
            $existing = $this->get($key);
            if ($existing && (($existing['state'] === 'ready' && $existing['expires_at'] > $this->now()) || in_array($existing['state'], ['pending', 'processing'], true))) return $existing;
            if ($existing && $existing['state'] === 'failed' && ($existing['retry_after'] ?? 0) > $this->now()) return $existing;
            $pending = 0; $actorPending = 0;
            $records = $this->records();
            if (!$existing && count($records) >= 1000) {
                foreach ($records as $index => $old) {
                    if (($old['request']['operation'] ?? '') === 'report' && in_array($old['state'], ['ready', 'failed'], true) && $old['updated_at'] < $this->now() - 86400) {
                        unlink($this->path($old['key'])); unset($records[$index]);
                    }
                }
                if (count($records) >= 1000) throw new \OverflowException('The report cache capacity is reached. Retry after older cached windows expire.');
            }
            foreach ($records as $record) if (in_array($record['state'], ['pending', 'processing'], true)) {
                $pending++;
                if (($record['request']['authorization_scope'] ?? null) === ($request['authorization_scope'] ?? null)) $actorPending++;
            }
            if ($pending >= 100) throw new \OverflowException('The report queue is full. Retry later.');
            if ($actorPending >= 10) throw new \OverflowException('Your queued report budget is full. Wait for existing requests.');
            $record = [
                'key' => $key, 'request' => $request, 'state' => 'pending',
                'created_at' => $existing['created_at'] ?? $this->now(), 'updated_at' => $this->now(),
                'queued_at' => microtime(true),
                'ttl' => $ttl, 'attempts' => 0,
            ];
            if (isset($existing['result'])) $record['last_good'] = $existing['result'];
            elseif (isset($existing['last_good'])) $record['last_good'] = $existing['last_good'];
            $this->put($key, $record);
            return $record;
        });
    }

    public function records(): array
    {
        $records = [];
        foreach (glob($this->root.'/*.json') ?: [] as $path) {
            $record = $this->get(basename($path, '.json'));
            if ($record) $records[] = $record;
        }
        usort($records, fn ($a, $b) => ($a['queued_at'] ?? $a['created_at'] ?? 0) <=> ($b['queued_at'] ?? $b['created_at'] ?? 0));
        return $records;
    }

    public function process(callable $execute, int $maxJobs = 3): array
    {
        $completed = [];
        $maxJobs = min(10, max(1, $maxJobs));
        $properties = [];
        foreach ($this->records() as $record) {
            if (count($completed) >= $maxJobs) break;
            if (!in_array($record['state'], ['pending', 'processing'], true)) continue;
            $key = $record['key'];
            $property = hash('sha256', ($record['request']['channel'] ?? '').':'.($record['request']['account'] ?? ''));
            if (isset($properties[$property])) continue;
            $propertyLock = fopen($this->root.'/property-'.$property.'.lock', 'c');
            if (!$propertyLock || !flock($propertyLock, LOCK_EX | LOCK_NB)) { if ($propertyLock) fclose($propertyLock); continue; }
            try {
                $claim = $this->locked(function () use ($key, $property) {
                    $cooldown = $this->get(hash('sha256', 'cooldown:'.$property));
                    if (($cooldown['until'] ?? 0) > $this->now()) return null;
                    $fresh = $this->get($key);
                    if (!$fresh || !in_array($fresh['state'], ['pending', 'processing'], true)) return null;
                    if ($fresh['state'] === 'processing' && ($fresh['lease_until'] ?? 0) > $this->now()) return null;
                    // A cross-process tenant source budget: each job uses at most two
                    // source requests (metadata + context probe, or compatibility + report).
                    $budgetKey = hash('sha256', 'source-budget');
                    $budget = $this->get($budgetKey) ?? ['used' => 0];
                    if (($budget['minute'] ?? null) !== intdiv($this->now(), 60)) $budget['used'] = 0;
                    if ($budget['used'] >= 10) return null;
                    $this->put($budgetKey, ['used' => $budget['used'] + 1, 'minute' => intdiv($this->now(), 60), 'state' => 'budget', 'created_at' => $this->now()]);
                    $fresh['state'] = 'processing';
                    $fresh['lease_token'] = bin2hex(random_bytes(16));
                    $fresh['lease_until'] = $this->now() + 300;
                    $fresh['attempts']++;
                    $this->put($key, $fresh);
                    return $fresh;
                });
                if (!$claim) continue;
                $properties[$property] = true;
                try {
                    $result = $execute($claim['request']);
                    if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 8 * 1024 * 1024) throw new \LengthException('Report exceeds its storage budget. Narrow its fields, dates or filters.');
                    if (!empty($result['source_delay_seconds'])) $this->locked(fn () => $this->put(hash('sha256', 'cooldown:'.$property), ['state' => 'budget', 'until' => $this->now() + min(86400, (int)$result['source_delay_seconds']), 'created_at' => $this->now()]));
                    $this->finish($key, $claim['lease_token'], ['state' => 'ready', 'result' => $result, 'expires_at' => $this->now() + $claim['ttl']]);
                } catch (\Throwable $error) {
                    $this->locked(fn () => $this->put(hash('sha256', 'cooldown:'.$property), ['state' => 'budget', 'until' => $this->now() + ((int)$error->getCode() === 429 ? 300 : 60), 'created_at' => $this->now()]));
                    $this->finish($key, $claim['lease_token'], [
                        'state' => 'failed', 'error' => $error instanceof \InvalidArgumentException || $error instanceof \LengthException ? $error->getMessage() : 'The source report could not be completed. Refresh the catalog or retry later.',
                        'exception_class' => $error::class, 'retry_after' => $this->now() + 300,
                    ]);
                }
                $completed[] = $key;
            } finally { flock($propertyLock, LOCK_UN); fclose($propertyLock); }
        }
        return $completed;
    }

    private function finish(string $key, string $token, array $changes): void
    {
        $this->locked(function () use ($key, $token, $changes) {
            $record = $this->get($key);
            if (($record['lease_token'] ?? null) !== $token) return;
            $this->put($key, array_replace($record, $changes, ['updated_at' => $this->now()]));
        });
    }

    private function put(string $key, array $record): void
    {
        $path = $this->path($key);
        $temporary = tempnam($this->root, 'write-');
        try {
            chmod($temporary, 0600);
            file_put_contents($temporary, json_encode($record, JSON_THROW_ON_ERROR));
            if (!rename($temporary, $path)) throw new \RuntimeException('Report cache write failed.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new \InvalidArgumentException('Invalid report identity.');
        return $this->root.'/'.$key.'.json';
    }

    private function locked(callable $callback): mixed
    {
        $lock = fopen($this->root.'/queue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Report queue lock unavailable.');
        try { return $callback(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
