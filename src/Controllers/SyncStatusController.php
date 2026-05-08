<?php

declare(strict_types=1);

namespace Controllers;

use Helpers\Helpers;
use Services\CacheService;
use Services\Sync\SyncTelemetryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SyncStatusController extends BaseController
{
    private SyncTelemetryService $telemetryService;

    public function __construct()
    {
        parent::__construct();
        $redis = Helpers::getRedisClient();
        $cacheService = CacheService::getInstance($redis);
        $this->telemetryService = new SyncTelemetryService($cacheService);
    }

    /**
     * GET /api/sync/status
     *
     * Params:
     * - channel: string (optional)
     * - account_id: string (optional)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatus(Request $request): JsonResponse
    {
        // 1. Authorization check
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $channel = $request->query->get('channel');
        $accountId = $request->query->get('account_id');

        try {
            $status = $this->telemetryService->getSyncStatus($channel, $accountId);
            return new JsonResponse($status);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to retrieve sync status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/sync/account-stats
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAccountStats(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $channel = $request->query->get('channel');
        $accountId = $request->query->get('account_id');

        if (!$channel || !$accountId) {
            return new JsonResponse(['error' => 'Missing channel or account_id'], 400);
        }

        try {
            $stats = $this->telemetryService->getAccountDailyStats($channel, $accountId);
            return new JsonResponse($stats);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to retrieve account stats',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Basic API Key authorization check
     *
     * @param Request $request
     * @return bool
     */
    private function isAuthorized(Request $request): bool
    {
        $apiKey = $request->headers->get('X-API-KEY') ?: $request->headers->get('X-Admin-API-Key');
        if (!$apiKey) {
            $authHeader = $request->headers->get('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $apiKey = substr($authHeader, 7);
            }
        }

        if (!$apiKey) {
            $apiKey = $request->query->get('api_key') ?: $request->query->get('token');
        }

        if (!$apiKey) {
            return false;
        }

        $validKeys = explode(',', Helpers::getAppApiKey() ?? '');
        $adminKey = Helpers::getAdminApiKey();
        if ($adminKey) {
            $validKeys[] = $adminKey;
        }

        return in_array(trim($apiKey), array_map('trim', $validKeys));
    }
}
