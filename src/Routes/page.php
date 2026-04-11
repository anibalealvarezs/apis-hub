<?php

use Controllers\ConfigManagerController;
use Controllers\MonitoringController;
use Controllers\PageController;
use Controllers\PrivacyController;
use Controllers\ManagementController;
use Controllers\PublicDataController;
use Controllers\SocialAuthController;
use Controllers\OAuthDispatcherController;
use Symfony\Component\HttpFoundation\Request;


$driverRoutes = (function() {
    $registry = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getRegistry();
    $routes = [];
    foreach ($registry as $config) {
        $driver = $config['driver'] ?? '';
        if (class_exists($driver) && method_exists($driver, 'getRoutes')) {
            $routes = array_replace($routes, $driver::getRoutes());
        }
    }
    return $routes;
})();

return array_merge($driverRoutes, [
    '/' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->home(),
        'public' => true,
        'html' => true
    ],
    '/privacy' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PrivacyController())->index(),
        'public' => true,
        'html' => true
    ],
    '/data-deletion' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PrivacyController())->dataDeletion(),
        'public' => true,
        'html' => true
    ],
    '/tos' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PrivacyController())->tos(),
        'public' => true,
        'html' => true
    ],
    '/auth/start/{channel}' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            $channel = $args['channel'] ?? '';
            return (new OAuthDispatcherController())->start($request, $channel);
        },
        'public' => true,
        'html' => true
    ],
    '/auth/callback/{channel}' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            $channel = $args['channel'] ?? '';
            return (new OAuthDispatcherController())->callback($request, $channel);
        },
        'public' => true,
        'html' => true
    ],
    '/api/auth/{channel}/import' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            $channel = $args['channel'] ?? '';
            return (new SocialAuthController())->importCredentials($request, $channel);
        },
        'public' => true,
        'html' => false,
        'admin' => false
    ],
    '/command-builder' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->commandBuilder(),
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/docs' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->docs(),
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/logs' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->logs(),
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/api/spec' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->apiSpec(),
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/monitoring' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new MonitoringController())->index();
        },
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/api/monitoring/data' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new MonitoringController())->data();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/jobs/action' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new MonitoringController())->jobAction($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/logs' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new MonitoringController())->logs($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/logs/list' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new MonitoringController())->logList();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/config-manager' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new ConfigManagerController())->index(),
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/api/config-manager/assets' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ConfigManagerController())->fetchAssets($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/update' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ConfigManagerController())->updateConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/validate-tokens' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ConfigManagerController())->validateTokens($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/export' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ConfigManagerController())->exportConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/flush-cache' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ConfigManagerController())->flushCache($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/update-credentials' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ManagementController())->updateCredentials($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/redeploy' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ManagementController())->triggerRedeploy($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/reset-channel' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ManagementController())->resetChannel($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/status' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new ManagementController())->getStatus();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/container/action' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new ManagementController())->containerAction($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],

    '/api/heartbeat' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new ManagementController())->getHeartbeat();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],

    // --- Public API v1 (Phase 6) ---

    '/api/v1/public/{channel}/{resource}' => [
        'httpMethod' => 'GET',
        'callable' => function(...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            $channel = $args['channel'] ?? '';
            $resource = $args['resource'] ?? '';
            return (new PublicDataController())->getResourceData($request, $channel, $resource);
        },
        'public' => true,
        'admin' => false
    ],
]);

