<?php

use Controllers\CrudController;

return [
    '/{entity}/create' => function (string $entity, ?string $body = null, ?array $params = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'create',
            body: $body
        );
    },
    '/{entity}/{id}' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'read',
            id: $id
        );
    },
    '/{entity}' => function (string $entity, ?string $body = null, ?array $params = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'list',
            body: $body,
            params: $params,
        );
    },
    '/{entity}/{id}/update' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'update',
            id: $id,
            body: $body
        );
    },
    '/{entity}/{id}/delete' => function (string $entity, int $id, ?string $body = null, ?array $params = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'delete',
            id: $id
        );
    },
];
