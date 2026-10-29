<?php

declare(strict_types=1);

namespace Services\Http;

use Symfony\Component\HttpFoundation\Request;

final class RequestBudgetPolicy
{
    public static function forRequest(Request $request): ?array
    {
        $path = rtrim($request->getPathInfo(), '/');
        if (in_array($path, ['/api/heartbeat', '/api/v1/ping', '/up'], true)) return null;
        $management = str_starts_with($path, '/api/management/') || str_contains($path, '/auth/') || str_contains($path, '/token');
        $orchestration = $path === '/api/compute-kpi';
        $expensive = !$management && str_ends_with($path, '/aggregate');
        $int = static fn (string $key, int $default) => max(1, (int)(getenv($key) ?: $default));
        return [
            'group' => $management ? 'management' : ($orchestration ? 'orchestration' : 'data'),
            'cost' => $expensive || $orchestration ? 5 : 1,
            'tenant_rate' => $int($management ? 'API_MANAGEMENT_BUDGET_PER_MINUTE' : ($orchestration ? 'API_KPI_BUDGET_PER_MINUTE' : 'API_TENANT_BUDGET_PER_MINUTE'), $management || $orchestration ? 60 : 600),
            'tenant_burst' => $int($management ? 'API_MANAGEMENT_BURST' : ($orchestration ? 'API_KPI_BURST' : 'API_TENANT_BUDGET_BURST'), $management || $orchestration ? 10 : 150),
            'actor_rate' => $int('API_ACTOR_BUDGET_PER_MINUTE', 300),
            'actor_burst' => $int('API_ACTOR_BUDGET_BURST', 120),
            // KPI orchestration calls back into aggregate endpoints. Separate
            // slots prevent parents from occupying all of their children's slots.
            'concurrency' => $orchestration ? $int('API_MAX_CONCURRENT_KPIS', 2)
                : ($management ? $int('API_MAX_CONCURRENT_MANAGEMENT_REQUESTS', 2)
                    : ($expensive ? $int('API_MAX_CONCURRENT_AGGREGATIONS', 4) : $int('API_MAX_CONCURRENT_READS', 8))),
            'lease_seconds' => $int('API_AGGREGATION_LEASE_SECONDS', 300),
        ];
    }
}
