<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use Classes\RoutingCore;

$entityManager = require_once __DIR__ . "/../app/bootstrap.php";

$request = Request::createFromGlobals();

$app = new RoutingCore();

// Cache routes first
$cacheRoutes = require_once __DIR__ . "/../src/Routes/cache.php";
$app->multiMap($cacheRoutes);

// CRUD routes last
$crudRoutes = require_once __DIR__ . "/../src/Routes/crud.php";
$app->multiMap($crudRoutes);

// Channeled CRUD routes next
$cacheRoutes = require_once __DIR__ . "/../src/Routes/channeledcrud.php";
$app->multiMap($cacheRoutes);

$response = $app->handle($request);

header('Content-Type: application/json');

$response->send();
