<?php

declare(strict_types=1);

// Permitir que el servidor integrado de PHP devuelva archivos estáticos directamente (CSS, ASSETS, etc.)
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    if (is_file(__DIR__ . '/..' . $path)) {
        return false; // Retornar falso le dice al cli-server que sirva el archivo estático
    }
}

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

// Page routes next
$pageRoutes = require_once __DIR__ . "/../src/Routes/page.php";
$app->multiMap($pageRoutes);

// Channeled CRUD routes next
$channeledRoutes = require_once __DIR__ . "/../src/Routes/channeledcrud.php";
$app->multiMap($channeledRoutes);

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
