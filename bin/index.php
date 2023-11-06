<?php

declare(strict_types=1);

require_once __DIR__ . "/../app/bootstrap.php";

use Symfony\Component\HttpFoundation\Request;
use Classes\Core;

$request = Request::createFromGlobals();

$app = new Core();

// Custom routes first
$analyticsRoutes = require_once __DIR__ . "/../src/Routes/analytics.php";
$app->multiMap($analyticsRoutes);

// CRUD routes last
$crudRoutes = require_once __DIR__ . "/../src/Routes/crud.php";
$app->multiMap($crudRoutes);

$response = $app->handle($request);

header('Content-Type: application/json');

$response->send();
