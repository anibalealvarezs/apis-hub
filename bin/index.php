<?php

declare(strict_types=1);

// Permitir que el servidor integrado de PHP devuelva archivos estáticos directamente (CSS, ASSETS, etc.)
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    
    // Si es un archivo que existe, dejarlo pasar nativamente
    if (is_file(__DIR__ . '/..' . $path)) {
        return false;
    }
    
    // Si la ruta parece un asset estático pero no existe, devolver 404 nativo y evitar bootear la app
    // EXCEPCIÓN: Las rutas /driver-assets/ son manejadas dinámicamente
    if (preg_match('/\.(?:png|jpg|jpeg|gif|ico|css|js|txt|base64|woff|woff2|ttf|eot|svg)$/i', $path) && !str_starts_with($path, '/driver-assets/')) {
        return false;
    }
}

require_once __DIR__ . "/../vendor/autoload.php";
\Helpers\Helpers::getProjectConfig(); // Inicialización temprana del entorno y archivo .env

use Symfony\Component\HttpFoundation\Request;
use Classes\RoutingCore;
use Symfony\Component\HttpFoundation\Response;

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

$entityManager = null;
try {
    $entityManager = require_once __DIR__ . "/../app/bootstrap.php";
    $response = $app->handle($request);
    $response->send();
} catch (Exception $e) {
    // Determine the user-facing message
    $isDebug = \Helpers\Helpers::isDebug();
    $status = 'error';
    $error = ($e instanceof \Exceptions\ConfigurationException) ? 'Configuration Error' : 'Internal Server Error';
    $message = $isDebug ? $e->getMessage() : 'An unexpected error occurred. Please check the logs or contact support.';
    
    // Optionally log the full error here if not already handled
    $logger = \Helpers\Helpers::setLogger('fatal_errors.log');
    $logger->error($e->getMessage(), ['exception' => $e]);

    $response = new Response(json_encode([
        'status' => $status,
        'error' => $error,
        'message' => $message
    ]), Response::HTTP_INTERNAL_SERVER_ERROR, [
        'Content-Type' => 'application/json'
    ]);
    $response->send();
} finally {

    if ($entityManager !== null) {
        $entityManager->close();
    }
    $entityManager = null;
}
