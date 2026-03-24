<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Response;

class PrivacyController
{
    /**
     * @Route("/privacy", name="app_privacy")
     */
    public function index(): Response
    {
        return $this->renderDocument('PRIVACY.md', 'privacy.html');
    }

    /**
     * @Route("/data-deletion", name="app_data_deletion")
     */
    public function dataDeletion(): Response
    {
        return $this->renderDocument('DATA_DELETION.md', 'data-deletion.html');
    }

    /**
     * @Route("/tos", name="app_tos")
     */
    public function tos(): Response
    {
        return $this->renderDocument('TOS.md', 'tos.html');
    }

    private function renderDocument(string $mdFile, string $htmlView): Response
    {
        $projectDir = dirname(__DIR__, 2);
        $mdPath = $projectDir . '/' . $mdFile;
        $viewPath = $projectDir . '/src/views/' . $htmlView;
        
        if (!file_exists($mdPath)) {
            return new Response($mdFile . ' file not found.', 404);
        }

        $markdown = file_get_contents($mdPath);

        // --- CONVERTIDOR DE MARKDOWN A HTML ---
        $html = $markdown;
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h2 id="' . strtolower(str_replace(' ', '-', '$1')) . '">$1</h2>', $html);
        $html = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $html);
        $html = preg_replace('/<([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>/', '<a href="mailto:$1">$1</a>', $html);
        $html = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
        $html = str_replace('</ul><ul>', '', $html);

        $lines = explode("\n", $html);
        $finalHtml = "";
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && !preg_match('/^<(h[1-3]|ul|li|a)/', $line)) {
                $finalHtml .= "<p>$line</p>\n";
            } else {
                $finalHtml .= $line . "\n";
            }
        }

        // --- RENDERIZADO EN LA VISTA ---
        $layout = file_exists($viewPath) ? file_get_contents($viewPath) : '<h1>Document</h1><div id="content">{{CONTENT}}</div>';
        $fullPage = str_replace('{{CONTENT}}', $finalHtml, $layout);

        return new Response($fullPage);
    }
}
