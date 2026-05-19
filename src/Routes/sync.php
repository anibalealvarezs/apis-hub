<?php

declare(strict_types=1);

use Controllers\SyncStatusController;
use Symfony\Component\HttpFoundation\Request;

return [
    '/api/sync/status' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new SyncStatusController())->getStatus($request);
        },
        'public' => true,
        'admin' => false
    ],
    '/api/sync/account-stats' => [
        'httpMethod' => 'GET',
        'callable' => function (...$args) {
            $request = $args['request'] ?? Request::createFromGlobals();
            return (new SyncStatusController())->getAccountStats($request);
        },
        'public' => true,
        'admin' => false
    ],
];
