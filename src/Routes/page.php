<?php

use Controllers\PageController;

return [
    '/' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->home(),
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
            return (new \Controllers\MonitoringController())->index();
        },
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/api/monitoring/data' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new \Controllers\MonitoringController())->data();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/jobs/action' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\MonitoringController())->jobAction($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/logs' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\MonitoringController())->logs($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/monitoring/logs/list' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new \Controllers\MonitoringController())->logList();
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/config-manager' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new \Controllers\ConfigManagerController())->index(),
        'public' => false,
        'html' => true,
        'admin' => true
    ],
    '/api/config-manager/assets' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\ConfigManagerController())->fetchAssets($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/update' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\ConfigManagerController())->updateConfig($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/validate-tokens' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\ConfigManagerController())->validateTokens($request);
        },
        'public' => false,
        'html' => false,
        'admin' => true
    ],
    '/api/config-manager/export' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\ConfigManagerController())->exportConfig($request);
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
