<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use Classes\RoutingCore;
use Symfony\Component\HttpFoundation\Response;

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

// Page routes next
$pageRoutes = require_once __DIR__ . "/../src/Routes/page.php";
$app->multiMap($pageRoutes);

try {
    $response = $app->handle($request);
    $response->send();
} catch (Exception $e) {
    $response = new Response();
    $response->setContent($e->getMessage());
    $response->setStatusCode(500);
    $response->send();
} finally {
    $entityManager->close();
    $entityManager = null;
}
