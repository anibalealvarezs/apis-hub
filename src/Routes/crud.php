<?php

use Controllers\CrudController;

return [
    '/entity/{entity}/create' => [
        'httpMethod' => 'POST',
        'callable' => function (string $entity, ?string $body = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'create',
                body: $body
            );
        },
        'admin' => true
    ],
    '/entity/{entity}/aggregate' => [
        'httpMethod' => 'POST',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'aggregate',
                body: $body,
                params: $params
            );
        }
    ],
    '/entity/{entity}/count' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'count',
                body: $body,
                params: $params,
            );
        },
        'admin' => true
    ],
    '/entity/{entity}/{id}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'read',
                id: $id
            );
        },
        'admin' => true
    ],
    '/entity/{entity}' => [
        'httpMethod' => 'GET',
        'callable' => function (string $entity, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'list',
                body: $body,
                params: $params,
            );
        },
        'admin' => true
    ],
    '/entity/{entity}/{id}/update' => [
        'httpMethod' => 'PUT',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'update',
                id: $id,
                body: $body
            );
        },
        'admin' => true
    ],
    '/entity/{entity}/{id}/delete' => [
        'httpMethod' => 'DELETE',
        'callable' => function (string $entity, int $id, ?string $body = null, ?array $params = null, ...$args) {
            return (new CrudController())(
                entity: $entity,
                method: 'delete',
                id: $id
            );
        },
        'admin' => true
    ]
];
