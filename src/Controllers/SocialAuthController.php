<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Helpers\Helpers;

class SocialAuthController
{
    /**
     * Import credentials from an external source (Facade)
     */
    public function importCredentials(Request $request, string $provider): Response
    {
        $providedToken = $request->headers->get('X-Admin-API-Key');
        $secretToken = $_ENV['ADMIN_API_KEY'] ?? null;

        if (!$secretToken || $providedToken !== $secretToken) {
            return new Response(json_encode(['error' => 'Unauthorized']), 401, ['Content-Type' => 'application/json']);
        }

        $data = json_decode($request->getContent(), true);
        $token = $data['access_token'] ?? null;
        $userId = $data['user_id'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $scopes = $data['scopes'] ?? [];

        $this->saveCredentials(
            token: (string)$token, 
            userId: $userId, 
            refreshToken: $refreshToken, 
            scopes: $scopes,
            provider: $provider
        );

        return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Persist tokens to provider-specific JSON files
     */
    private function saveCredentials(string $token, ?string $userId = null, ?string $refreshToken = null, array $scopes = [], string $provider = 'facebook'): void
    {
        $projectDir = dirname(__DIR__, 2);
        
        $providerMap = [
            'facebook' => [
                'path' => $_ENV['FACEBOOK_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/facebook_tokens.json',
                'key' => 'facebook_marketing'
            ],
            'google' => [
                'path' => $_ENV['GOOGLE_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/google_tokens.json',
                'key' => 'google_search_console'
            ]
        ];

        $config = $providerMap[$provider] ?? $providerMap['facebook'];
        $tokenPath = $config['path'];
        $tokenKey = $config['key'];
        
        if (!is_dir(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0755, true);
        }

        $tokens = file_exists($tokenPath) ? (json_decode(file_get_contents($tokenPath), true) ?? []) : [];
        
        $tokens[$tokenKey] = [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'user_id' => $userId,
            'scopes' => $scopes,
            'updated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime($provider === 'google' ? '+3600 seconds' : '+60 days'))
        ];
        
        file_put_contents($tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
