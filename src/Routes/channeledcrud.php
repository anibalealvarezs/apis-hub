<?php

use Controllers\ChanneledCrudController;

return [
    '/{channel}/{entity}/count' => [
        'httpMethod' => 'GET',
        'callable' => function (string $channel, string $entity, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'count',
                body: $body,
                params: $params,
            );
        },
        'admin' => false
    ],
    '/{channel}/{entity}/aggregate' => [
        'httpMethod' => 'POST',
        'callable' => function (string $channel, string $entity, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'aggregate',
                body: $body,
                params: $params,
            );
        },
        'admin' => false
    ],
    '/{channel}/{entity}/range' => [
        'httpMethod' => 'GET',
        'callable' => function (string $channel, string $entity, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'range',
                body: $body,
                params: $params,
            );
        },
        'admin' => false
    ],
    '/{channel}/{entity}/{id}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $channel, string $entity, string|int $id, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'read',
                id: $id
            );
        },
        'admin' => false
    ],
    '/{channel}/{entity}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $channel, string $entity, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'list',
                body: $body,
                params: $params,
            );
        },
        'admin' => false
    ],
];
