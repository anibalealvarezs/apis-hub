<?php

namespace Tests\Unit\Services\Http;

use PHPUnit\Framework\TestCase;
use Services\Http\RequestBudgetPolicy;
use Symfony\Component\HttpFoundation\Request;

class RequestBudgetPolicyTest extends TestCase
{
    public function testInternalCredentialsDoNotBypassResourceBudgets(): void
    {
        $request = Request::create('/google_search_console/metric/aggregate', 'POST');
        $public = RequestBudgetPolicy::forRequest($request);
        $request->headers->set('X-Admin-API-Key', 'test-only-key');
        self::assertSame($public, RequestBudgetPolicy::forRequest($request));
        self::assertSame(5, $public['cost']);
        self::assertGreaterThan(0, $public['concurrency']);
    }

    public function testHealthManagementAndKpiOrchestrationHaveIndependentCapacity(): void
    {
        self::assertNull(RequestBudgetPolicy::forRequest(Request::create('/api/heartbeat')));
        $data = RequestBudgetPolicy::forRequest(Request::create('/google_search_console/metric/attributes', 'POST'));
        $management = RequestBudgetPolicy::forRequest(Request::create('/api/management/redeploy', 'POST'));
        $kpi = RequestBudgetPolicy::forRequest(Request::create('/api/compute-kpi', 'POST'));
        self::assertSame(1, $data['cost']);
        self::assertSame('management', $management['group']);
        self::assertSame('orchestration', $kpi['group']);
        self::assertGreaterThan(0, $kpi['concurrency']);
        self::assertNotSame($data['group'], $kpi['group']);
    }
}
