<?php

use Controllers\PageController;

return [
    '/' => [
        'httpMethod' => 'GET',
        'callable' => fn(...$args) => (new PageController())->home(),
    ]
];
