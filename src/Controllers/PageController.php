<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Response;

class PageController extends BaseController
{
    public function home(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/home.html');
        return $this->renderWithEnv($html);
    }

    public function commandBuilder(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/command-builder.html');
        return $this->renderWithEnv($html);
    }

    public function docs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/api-docs.html');
        return $this->renderWithEnv($html);
    }

    public function apiSpec(): Response
    {
        $json = file_get_contents(__DIR__ . '/../views/openapi.json');
        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/monitoring.html');
        return $this->renderWithEnv($html);
    }

    public function logs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/logs.html');
        return $this->renderWithEnv($html);
    }

    public function authLogin(string $channel): Response
    {
        $channelName = ucwords(str_replace(['_', '-'], ' ', $channel));
        
        $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Authenticate $channelName - APIs Hub</title>
                <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
                <style>
                    :root {
                        --bg: #0d1117;
                        --card: #161b22;
                        --border: #30363d;
                        --text: #c9d1d9;
                        --primary: #58a6ff;
                        --success: #238636;
                    }
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: 'Inter', sans-serif;
                        background: radial-gradient(circle at center, #1b2129 0%, #0d1117 100%);
                        color: var(--text);
                        height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .card {
                        background: var(--card);
                        border: 1px solid var(--border);
                        border-radius: 20px;
                        padding: 48px;
                        max-width: 440px;
                        width: 90%;
                        text-align: center;
                        box-shadow: 0 10px 40px rgba(0,0,0,0.5);
                        backdrop-filter: blur(10px);
                    }
                    .logo-container {
                        width: 80px;
                        height: 80px;
                        background: #30363d;
                        border-radius: 20px;
                        margin: 0 auto 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 40px;
                        color: #fff;
                        font-weight: 700;
                    }
                    h1 {
                        margin: 0 0 12px;
                        font-size: 24px;
                        font-weight: 700;
                        color: #fff;
                    }
                    p {
                        margin: 0 0 32px;
                        color: #8b949e;
                        font-size: 16px;
                        line-height: 1.5;
                    }
                    .btn {
                        display: block;
                        background: var(--primary);
                        color: #fff;
                        text-decoration: none;
                        padding: 14px;
                        border-radius: 12px;
                        font-weight: 600;
                        transition: all 0.2s;
                        font-size: 16px;
                    }
                    .btn:hover {
                        filter: brightness(1.1);
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(88, 166, 255, 0.3);
                    }
                    .footer {
                        margin-top: 32px;
                        font-size: 12px;
                        color: #484f58;
                    }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='logo-container'>
                        " . $this->getChannelIcon($channel) . "
                    </div>
                    <h1>Connect to $channelName</h1>
                    <p>APIs Hub needs access to your $channelName account to synchronize metrics and generate reporting dashboards.</p>
                    <a href='/auth/start/$channel' class='btn'>Continue with Authorization</a>
                    <div class='footer'>
                        Securely handled by APIs Hub Auth Dispatcher
                    </div>
                </div>
            </body>
            </html>
        ";

        return new Response($html);
    }

    private function getChannelIcon(string $channel): string
    {
        if (str_contains($channel, 'facebook') || str_contains($channel, 'meta')) {
            return 'M'; // Meta
        }
        if (str_contains($channel, 'google')) {
            return 'G'; // Google
        }
        if (str_contains($channel, 'amazon')) {
            return 'A'; // Amazon
        }
        if (str_contains($channel, 'shopify')) {
            return 'S'; // Shopify
        }
        return substr(ucfirst($channel), 0, 1);
    }
}
