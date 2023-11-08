<?php

declare(strict_types=1);

require_once __DIR__ . "/../app/bootstrap.php";

use Symfony\Component\HttpFoundation\Request;
use Classes\RoutingCore;

$request = Request::createFromGlobals();

$app = new RoutingCore();

// Custom routes first
$cacheRoutes = require_once __DIR__ . "/../src/Routes/cache.php";
$app->multiMap($cacheRoutes);

// CRUD routes last
$crudRoutes = require_once __DIR__ . "/../src/Routes/crud.php";
$app->multiMap($crudRoutes);

$response = $app->handle($request);

header('Content-Type: application/json');

$response->send();
