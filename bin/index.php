<?php

declare(strict_types=1);

$loader = require_once __DIR__ . "/../vendor/autoload.php";
$loader->register();

use Symfony\Component\HttpFoundation\Request;
use Classes\Core;
use Classes\Crud;

$request = Request::createFromGlobals();

$app = new Core();

$app->map('/bin/{entities}/create/{data?}', function (string $entities, string $data = null) {
    return (new Crud())(
        entity: $entities,
        method: 'create',
        data: $data
    );
});

$app->map('/bin/{entities}/{id}/update/{data?}', function (string $entities, int $id, string $data = null) {
    return (new Crud())(
        entity: $entities,
        method: 'update',
        id: $id,
        data: $data
    );
});

$app->map('/bin/{entities}/{id}/delete', function (string $entities, int $id) {
    return (new Crud())(
        entity: $entities,
        method: 'delete',
        id: $id
    );
});

$app->map('/bin/{entities}/{id?}/{data?}', function (string $entities, int $id = null, string $data = null) {
    return (new Crud())(
        entity: $entities,
        method: 'read',
        id: $id,
        data: $data
    );
});

$response = $app->handle($request);
$response->send();
