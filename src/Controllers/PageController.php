<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Response;

class PageController extends BaseController
{
    public function home(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/home.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function commandBuilder(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/command-builder.html');
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

    public function monitoring(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/monitoring.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function logs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/logs.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function facebookReports(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/facebook-reports.html');
        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }
}
