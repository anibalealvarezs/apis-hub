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
        $projectDir = dirname(__DIR__, 2);
        $privacyFilePath = $projectDir . '/PRIVACY.md';
        $viewPath = $projectDir . '/src/views/privacy.html';
        
        if (!file_exists($privacyFilePath)) {
            return new Response('Privacy policy file not found.', 404);
        }

        $markdown = file_get_contents($privacyFilePath);

        // --- CONVERTIDOR DE MARKDOWN A HTML (Schema & Links Preservation) ---
        
        $html = $markdown;
        
        // 1. Títulos (Headers)
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h2 id="' . strtolower(str_replace(' ', '-', '$1')) . '">$1</h2>', $html);
        $html = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $html);
        
        // Corregir IDs de H2 para los enlaces internos basándonos en tu esquema
        $html = preg_replace_callback('/<h2 id="(.*?)">(.*?)<\/h2>/', function($m) {
            $id = strtolower(trim($m[2]));
            $id = str_replace(' ', '-', $id);
            $id = preg_replace('/[^a-z0-9-]/', '', $id);
            return "<h2 id=\"$id\">{$m[2]}</h2>";
        }, $html);

        // 2. Negritas (Bold)
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);

        // 3. Enlaces (Links) - [text](#link) y [text](url)
        $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $html);
        
        // 4. Listas (Lists)
        $html = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
        // Limpiar el anidamiento excesivo de <ul> provocado por el regex simplificado
        $html = str_replace('</ul><ul>', '', $html);

        // 5. Párrafos (Paragraphs)
        // Separamos bloques por saltos de línea dobles
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

        $layout = file_exists($viewPath) ? file_get_contents($viewPath) : '<h1>Privacy Policy</h1><div id="content">{{CONTENT}}</div>';
        $fullPage = str_replace('{{CONTENT}}', $finalHtml, $layout);

        return new Response($fullPage);
    }
}
