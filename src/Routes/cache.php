<?php

use Controllers\CacheController;
use Helpers\Helpers;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

return [
    '/cache/interrupt' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $input = (array) Helpers::bodyToObject(data: $body);
            $channel = $input['channel'] ?? $params['channel'] ?? null;
            $entity = $input['entity'] ?? $params['entity'] ?? null;

            return (new CacheController())->interruptJobs(
                channel: $channel,
                entity: $entity
            );
        }
    ],
    '/cache/reset/{entity}' => [
        'httpMethod' => 'POST',
        'callable' => function (string $entity, ?array $ids = null, bool $includeListAndCount = true, ?string $channel = null, ?string $body = null, ?array $params = null) {
            return (new CacheService(Helpers::getRedisClient()))->invalidateEntityCache(
                entity: $entity,
                ids: $ids,
                includeListAndCount: $includeListAndCount,
                channel: $channel,
            ) ? new Response(
                content: json_encode(value: [
                    'data' => [],
                    'status' => 'success',
                    'error' => null
                ]),
                status: 200,
                headers: ['Content-Type' => 'application/json']
            ) : new Response(
                content: json_encode(value: [
                    'data' => null,
                    'status' => 'error',
                    'error' => 'Cache not invalidated'
                ]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
                headers: ['Content-Type' => 'application/json']
            );
        }
    ],
    '/cache/{channel}/{entity}' => [
        'httpMethod' => 'POST',
        'callable' => function (string $channel, string $entity, ?string $body = null, ?array $params = null) {
            return (new CacheController())(
                channel: $channel,
                entity: $entity,
                body: $body,
                params: $params,
            );
        }
    ],
];
