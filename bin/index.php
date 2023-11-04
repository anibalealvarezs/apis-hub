<?php

declare(strict_types=1);

require_once __DIR__ . "/../app/bootstrap.php";

use Classes\Analytics;
use Symfony\Component\HttpFoundation\Request;
use Classes\Core;

$request = Request::createFromGlobals();

$app = new Core();

$crudRoutes = require_once __DIR__ . "/../src/Routes/crud.php";

$app->multiMap($crudRoutes);

$app->map('/analytics/{channel}/{entity}', function (string $entity, int $id) {
    return (new Analytics())(
        entity: $entity,
        method: 'delete',
        id: $id
    );
});

$response = $app->handle($request);
$response->send();
