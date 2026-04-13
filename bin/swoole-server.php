<?php

declare(strict_types=1);

/**
 * Swoole HTTP Server for APIs Hub
 * 
 * Replaces 'php -S' for high-concurrency production environments.
 * Handles graceful shutdown, static assets, and memory management.
 */

use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Request;
use Classes\RoutingCore;

require_once __DIR__ . "/../vendor/autoload.php";

// Initialize environment
\Helpers\Helpers::getProjectConfig();

$host = '0.0.0.0';
$port = (int) (getenv('PORT') ?: 8080);
$useSsl = (getenv('USE_SSL') === 'true');

$mode = SWOOLE_PROCESS;
$sockType = SWOOLE_SOCK_TCP;

if ($useSsl) {
    $sockType |= SWOOLE_SSL;
}

$server = new Server($host, $port, $mode, $sockType);

$serverSettings = [
    'worker_num' => swoole_cpu_num() * 2,
    'enable_static_handler' => true,
    'document_root' => __DIR__ . '/..',
    'static_handler_locations' => ['/assets'],
    'max_request' => 1000, // Restart worker after 1000 requests to prevent leaks
    'dispatch_mode' => 1,
];

if ($useSsl) {
    $serverSettings['ssl_cert_file'] = __DIR__ . '/../storage/certs/cert.pem';
    $serverSettings['ssl_key_file'] = __DIR__ . '/../storage/certs/key.pem';
}

$server->set($serverSettings);

$server->on("Start", function (Server $server) use ($host, $port, $useSsl) {
    $protocol = $useSsl ? 'https' : 'http';
    echo "Swoole HTTP server is started at {$protocol}://{$host}:{$port}\n";
});

// 1. Initialize environment and boot drivers
require_once __DIR__ . "/../app/bootstrap.php";

// 2. Pre-register routes
$app = new RoutingCore();
$app->multiMap(require __DIR__ . "/../src/Routes/cache.php");
$app->multiMap(require __DIR__ . "/../src/Routes/crud.php");
$app->multiMap(require __DIR__ . "/../src/Routes/page.php");
$app->multiMap(require __DIR__ . "/../src/Routes/channeledcrud.php");

$server->on("Request", function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($app) {
    // 1. Convert Swoole Request to Symfony Request
    $get = $swooleRequest->get ?? [];
    $post = $swooleRequest->post ?? [];
    $cookie = $swooleRequest->cookie ?? [];
    $files = $swooleRequest->files ?? [];
    $server = array_change_key_case($swooleRequest->server ?? [], CASE_UPPER);
    
    // Fix headers
    $headers = [];
    foreach ($swooleRequest->header as $key => $value) {
        $headers['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }
    $server = array_merge($server, $headers);

    $request = new Request($get, $post, [], $cookie, $files, $server, $swooleRequest->rawContent());

    try {
        $entityManager = \Helpers\Helpers::getManager();
        $response = $app->handle($request);
        
        // 2. Convert Symfony Response to Swoole Response
        $swooleResponse->status($response->getStatusCode());
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, (string) $value);
            }
        }
        $swooleResponse->end($response->getContent());

    } catch (Exception $e) {
        $isDebug = \Helpers\Helpers::isDebug();
        $status = 'error';
        $error = ($e instanceof \Exceptions\ConfigurationException) ? 'Configuration Error' : 'Internal Server Error';
        $message = $isDebug ? $e->getMessage() : 'An unexpected error occurred.';
        
        $logger = \Helpers\Helpers::setLogger('swoole_errors.log');
        $logger->error($e->getMessage(), ['exception' => $e]);

        $swooleResponse->status(500);
        $swooleResponse->header('Content-Type', 'application/json');
        $swooleResponse->end(json_encode([
            'status' => $status,
            'error' => $error,
            'message' => $message
        ]));
    } finally {
        $entityManager = \Helpers\Helpers::getManager();
        if ($entityManager && $entityManager->isOpen()) {
            $entityManager->close();
        }
    }
});

$server->start();
