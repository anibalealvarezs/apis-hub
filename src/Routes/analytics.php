<?php

use Controllers\ScopedAnalyticsController;
use Symfony\Component\HttpFoundation\Request;

return [
    '/api/analytics/catalog' => [
        'httpMethod' => 'POST', 'admin' => true,
        'callable' => fn (...$args) => ScopedAnalyticsController::handle($args['request'] ?? Request::createFromGlobals(), 'catalog'),
    ],
    '/api/analytics/reports/query' => [
        'httpMethod' => 'POST', 'admin' => true,
        'callable' => fn (...$args) => ScopedAnalyticsController::handle($args['request'] ?? Request::createFromGlobals(), 'query'),
    ],
];
