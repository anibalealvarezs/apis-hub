<?php

use Controllers\CacheController;

return [
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
