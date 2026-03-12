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
        'public' => true,
        'html' => true
    ],
    '/docs' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->docs(),
        'public' => true,
        'html' => true
    ],
    '/api/spec' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->apiSpec(),
        'public' => true,
        'html' => false
    ],
    '/monitoring' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new \Controllers\MonitoringController())->index();
        },
        'public' => true,
        'html' => true
    ],
    '/api/monitoring/data' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            return (new \Controllers\MonitoringController())->data();
        },
        'public' => true, // Make it public for now, or add auth if needed
        'html' => false
    ],
    '/api/monitoring/jobs/action' => [
        'httpMethod' => 'POST',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\MonitoringController())->jobAction($request);
        },
        'public' => true,
        'html' => false
    ],
    '/api/monitoring/logs' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            return (new \Controllers\MonitoringController())->logs($request);
        },
        'public' => true,
        'html' => false
    ]
];
