<?php

use Controllers\CrudController;

return [
    '/{entity}/create' => [
        'httpMethod' => 'POST',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'create',
                body: $body
            );
        }
    ],
    '/{entity}/{id}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'read',
                id: $id
            );
        }
    ],
    '/{entity}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'list',
                body: $body,
                params: $params,
            );
        }
    ],
    '/{entity}/{id}/update' => [
        'httpMethod' => 'PUT',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'update',
                id: $id,
                body: $body
            );
        }
    ],
    '/{entity}/{id}/delete' => [
        'httpMethod' => 'DELETE',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'delete',
                id: $id
            );
        }
    ]
];
