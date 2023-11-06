<?php

use Controllers\AnalyticsController;

return [
    '/analytics/{entity}/{id}' => function (string $entity, int $id) {
        return (new AnalyticsController())(
            entity: $entity,
            method: 'read',
            id: $id
        );
    },
    '/analytics/{entity}/{data?}' => function (string $entity, string $data = null) {
        return (new AnalyticsController())(
            entity: $entity,
            method: 'list',
            data: $data
        );
    },
];
