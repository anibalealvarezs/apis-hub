<?php

use Controllers\PageController;

return [
    '/' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->home(),
        'public' => true,
        'html' => true
    ],
    '/dev-monitor' => [
        'httpMethod' => 'GET',
        'callable' => fn (...$args) => (new PageController())->devMonitor(),
        'public' => true,
        'html' => true
    ]
];
