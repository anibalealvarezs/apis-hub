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
}