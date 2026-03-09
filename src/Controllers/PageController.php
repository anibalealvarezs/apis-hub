<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Response;

class PageController
{
    public function home(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/home.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function devMonitor(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/dev-monitor.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function docs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/api-docs.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function apiSpec(): Response
    {
        $json = file_get_contents(__DIR__ . '/../views/openapi.json');
        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }
}
