<?php

use Controllers\ChanneledCrudController;

return [
    '/{channel}/{entity}/{id}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $channel, string $entity, int $id, ?string $body = null, ?array $params = null) {
            return (new ChanneledCrudController())(
                entity: $entity,
                channel: $channel,
                method: 'read',
                id: $id
            );
        }
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
        }
    ],
];
