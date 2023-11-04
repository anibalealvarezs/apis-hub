<?php

declare(strict_types=1);

require_once __DIR__ . "/../app/bootstrap.php";

use Classes\Analytics;
use Symfony\Component\HttpFoundation\Request;
use Classes\Core;
use Classes\Crud;

$request = Request::createFromGlobals();

$app = new Core();

$app->map('/{entity}/create/{data?}', function (string $entity, string $data = null) {
    return (new Crud())(
        entity: $entity,
        method: 'create',
        data: $data
    );
});

$app->map('/{entity}/{id}', function (string $entity, int $id) {
    return (new Crud())(
        entity: $entity,
        method: 'read',
        id: $id
    );
});

$app->map('/{entity}/{data?}', function (string $entity, string $data = null) {
    return (new Crud())(
        entity: $entity,
        method: 'read',
        data: $data
    );
});

$app->map('/{entity}/{id}/update/{data?}', function (string $entity, int $id, string $data = null) {
    return (new Crud())(
        entity: $entity,
        method: 'update',
        id: $id,
        data: $data
    );
});

$app->map('/{entity}/{id}/delete', function (string $entity, int $id) {
    return (new Crud())(
        entity: $entity,
        method: 'delete',
        id: $id
    );
});

$app->map('/analytics/{channel}/{entity}', function (string $entity, int $id) {
    return (new Analytics())(
        entity: $entity,
        method: 'delete',
        id: $id
    );
});

$response = $app->handle($request);
$response->send();
