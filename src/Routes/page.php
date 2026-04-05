<?php

use Controllers\ConfigManagerController;
use Controllers\MonitoringController;
use Controllers\PageController;
use Controllers\FacebookAuthController;
use Controllers\PrivacyController;
use Controllers\ManagementController;
use Controllers\PublicDataController;
use Symfony\Component\HttpFoundation\Request;


return [
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
    '/fb-login' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new FacebookAuthController())->login(),
        'public' => true,
        'html' => true
    ],
    '/fb-auth-start' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new FacebookAuthController())->start(),
        'public' => true,
        'html' => true
    ],
    '/fb-callback' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new FacebookAuthController())->callback(Request::createFromGlobals());
        },
        'public' => true,
        'html' => true
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
            $request = Request::createFromGlobals();
            return (new MonitoringController())->jobAction($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/logs' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
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
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'GET', $params ?? [], [], [], [], $body);
            return (new ConfigManagerController())->fetchAssets($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/update' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
            return (new ConfigManagerController())->updateConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/validate-tokens' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
            return (new ConfigManagerController())->validateTokens($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/export' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
            return (new ConfigManagerController())->exportConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/flush-cache' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            return (new ConfigManagerController())->flushCache($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/fb-reports' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->facebookReports(),
        'public' => ($_ENV['APP_ENV'] ?? '') === 'testing' || str_contains(strtolower($_ENV['PROJECT_NAME'] ?? ''), 'demo'),
        'html' => true,
        'admin' => false
    ],
    '/fb-organic-reports' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->facebookOrganicReports(),
        'public' => ($_ENV['APP_ENV'] ?? '') === 'testing' || str_contains(strtolower($_ENV['PROJECT_NAME'] ?? ''), 'demo'),
        'html' => true,
        'admin' => false
    ],
    '/gsc-reports' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->gscReports(),
        'public' => ($_ENV['APP_ENV'] ?? '') === 'testing' || str_contains(strtolower($_ENV['PROJECT_NAME'] ?? ''), 'demo'),
        'html' => true,
        'admin' => false
    ],
    '/api/management/update-credentials' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
            return (new ManagementController())->updateCredentials($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/redeploy' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
            return (new ManagementController())->triggerRedeploy($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/management/reset-channel' => [
        'httpMethod' => 'POST',
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
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
        'callable' => function (?string $body = null, ?array $params = null) {
            $request = Request::create('', 'POST', $params ?? [], [], [], [], $body);
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

    '/api/v1/public/facebook/campaigns' => [
        'httpMethod' => 'GET',
        'callable' => fn(...$args) => (new PublicDataController())->getFacebookCampaigns(Request::createFromGlobals()),
        'public' => true,
        'admin' => false
    ],
    '/api/v1/public/facebook/metrics' => [
        'httpMethod' => 'GET',
        'callable' => fn(...$args) => (new PublicDataController())->getMetrics(Request::createFromGlobals(), 'facebook'),
        'public' => true,
        'admin' => false
    ],
    '/api/v1/public/gsc/metrics' => [
        'httpMethod' => 'GET',
        'callable' => fn(...$args) => (new PublicDataController())->getMetrics(Request::createFromGlobals(), 'gsc'),
        'public' => true,
        'admin' => false
    ]
];

