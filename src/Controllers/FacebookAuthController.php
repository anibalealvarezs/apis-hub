<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Yaml\Yaml;

class FacebookAuthController
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        // Se cargan desde el .env (basado en la estructura del proyecto)
        $this->clientId = $_ENV['FACEBOOK_APP_ID'] ?? '';
        $this->clientSecret = $_ENV['FACEBOOK_APP_SECRET'] ?? '';
        $this->redirectUri = $_ENV['FACEBOOK_REDIRECT_URI'] ?? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]/fb-callback";
    }

    /**
     * Muestra la página de inicio del Login
     */
    public function login(): Response
    {
        $viewPath = dirname(__DIR__, 2) . '/src/views/fb-login.html';
        $content = file_exists($viewPath) ? file_get_contents($viewPath) : '<h1>Login with Meta</h1><a href="/fb-auth-start">Continue</a>';
        return new Response($content);
    }

    /**
     * Inicia el flujo OAuth redirigiendo a Facebook
     */
    public function start(): RedirectResponse
    {
        if (empty($this->clientId)) {
            return new RedirectResponse('/fb-login?error=invalid_config');
        }

        $scopes = [
            'public_profile',
            'email',
            'ads_read', // Requerido para ver insights
            'ads_management' // Opcional, pero recomendado para gestión estándar
        ];

        $url = "https://www.facebook.com/v19.0/dialog/oauth?" . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
            'state' => bin2hex(random_bytes(16)) // Seguridad anti-CSRF
        ]);

        return new RedirectResponse($url);
    }

    /**
     * Maneja el retorno de Facebook con el código de autorización
     */
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        if (!$code) {
            return new Response("Authorization code missing.", 400);
        }

        // 1. Intercambiar code por Short-Lived User Token (2h)
        $tokenUrl = "https://graph.facebook.com/v19.0/oauth/access_token?" . http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code
        ]);

        $response = json_decode(file_get_contents($tokenUrl), true);
        $shortLivedToken = $response['access_token'] ?? null;

        if (!$shortLivedToken) {
            return new Response("Failed to retrieve access token.", 500);
        }

        // 2. Intercambiar por Long-Lived User Token (60 días)
        $exchangeUrl = "https://graph.facebook.com/v19.0/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'fb_exchange_token' => $shortLivedToken
        ]);

        $exchangeResponse = json_decode(file_get_contents($exchangeUrl), true);
        $longLivedToken = $exchangeResponse['access_token'] ?? null;

        if (!$longLivedToken) {
            return new Response("Failed to exchange long-lived token.", 500);
        }

        // 3. PERSISTENCIA: Guardamos las credenciales
        $this->saveCredentials($longLivedToken);

        return new Response("
            <div style='background: #0a0c10; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: sans-serif; text-align: center; padding: 20px;'>
                <div style='background: #161b22; border: 1px solid #30363d; padding: 40px; border-radius: 20px; max-width: 400px;'>
                    <h1 style='color: #238636;'>✓ Success!</h1>
                    <p style='color: #8b949e;'>Your Meta Ads credentials have been successfully updated and stored in APIs Hub.</p>
                    <a href='/fb-reports' style='display: inline-block; margin-top: 20px; background: #58a6ff; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none;'>Back to Reports</a>
                </div>
            </div>
        ");
    }

    /**
     * Guarda el token en el archivo de tokens JSON (excluido de Git)
     */
    private function saveCredentials(string $token): void
    {
        $projectDir = dirname(__DIR__, 2);
        // Usamos la ruta definida en .env o una por defecto
        $tokenPath = $_ENV['FACEBOOK_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/facebook_tokens.json';
        
        // Aseguramos que el directorio existe
        $dir = dirname($tokenPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tokens = [];
        if (file_exists($tokenPath)) {
            $tokens = json_decode(file_get_contents($tokenPath), true) ?? [];
        }
        
        // Almacenamos el token con metadatos de expiración (60 días aprox)
        $tokens['facebook_marketing'] = [
            'access_token' => $token,
            'updated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+60 days'))
        ];
        
        // Guardamos el JSON de forma legible
        file_put_contents($tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
