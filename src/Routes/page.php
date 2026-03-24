<?php

use Controllers\ConfigManagerController;
use Controllers\MonitoringController;
use Controllers\PageController;
use Controllers\PrivacyController;
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
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            return (new ConfigManagerController())->fetchAssets($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/update' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            return (new ConfigManagerController())->updateConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/validate-tokens' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
            return (new ConfigManagerController())->validateTokens($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/export' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = Request::createFromGlobals();
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
        'public' => false,
        'html' => true,
        'admin' => false
    ]
];
