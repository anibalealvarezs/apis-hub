<?php

namespace Controllers;

use Helpers\Helpers;
use Services\Analytics\ScopedReportService;
use Services\Analytics\ScopedReportStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ScopedAnalyticsController
{
    public static function handle(Request $request, string $operation): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 65536) return new JsonResponse(['error' => 'Report definition exceeds its size budget.'], 413);
            $input = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $service = new ScopedReportService(new ScopedReportStore(dirname(__DIR__, 2).'/storage/analytics-reports'), Helpers::getManager()->getConnection());
            // This route is admin-only; the trusted Facade supplies a stable published
            // widget scope or the authenticated user's scope, never browser input.
            $actor = hash('sha256', (string)($input['authorization_scope'] ?? $request->headers->get('X-Request-Actor', 'admin')));
            $channel = (string)($input['channel'] ?? '');
            $account = (int)($input['account'] ?? 0);
            $data = $operation === 'catalog' ? $service->catalog($channel, $account, $actor, !empty($input['refresh']))
                : $service->query($channel, $account, (array)($input['definition'] ?? []), $actor);
            return new JsonResponse(['success' => true, 'data' => $data]);
        } catch (\OverflowException $error) {
            return new JsonResponse(['error' => $error->getMessage(), 'retryable' => true], 429, ['Retry-After' => '60']);
        } catch (\InvalidArgumentException | \JsonException $error) {
            return new JsonResponse(['error' => $error->getMessage(), 'retryable' => false], 422);
        } catch (\Throwable $error) {
            error_log('Scoped report request failed: '.$error::class);
            return new JsonResponse(['error' => 'Scoped analytics storage or driver is unavailable.', 'retryable' => true], 503);
        }
    }
}
