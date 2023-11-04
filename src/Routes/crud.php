<?php

use Controllers\CrudController;

return [
    '/{entity}/create/{data?}' => function (string $entity, string $data = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'create',
            data: $data
        );
    },
    '/{entity}/{id}' => function (string $entity, int $id) {
        return (new CrudController())(
            entity: $entity,
            method: 'read',
            id: $id
        );
    },
    '/{entity}/{data?}' => function (string $entity, string $data = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'list',
            data: $data
        );
    },
    '/{entity}/{id}/update/{data?}' => function (string $entity, int $id, string $data = null) {
        return (new CrudController())(
            entity: $entity,
            method: 'update',
            id: $id,
            data: $data
        );
    },
    '/{entity}/{id}/delete' => function (string $entity, int $id) {
        return (new CrudController())(
            entity: $entity,
            method: 'delete',
            id: $id
        );
    },
];
