<?php

use Controllers\CrudController;

return [
    '/entity/{entity}/create' => [
        'httpMethod' => 'POST',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'create',
                body: $body
            );
        }
    ],
    '/entity/{entity}/count' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'count',
                body: $body,
                params: $params,
            );
        }
    ],
    '/entity/{entity}/{id}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
            return (new CrudController())(
                entity: $entity,
                method: 'read',
                id: $id
            );
        }
    ],
    '/entity/{entity}' => [
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
    '/entity/{entity}/{id}/update' => [
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
    '/entity/{entity}/{id}/delete' => [
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
