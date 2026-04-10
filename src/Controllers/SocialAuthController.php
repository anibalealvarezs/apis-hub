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
     * Persist tokens to provider-specific storage via drivers
     */
    private function saveCredentials(string $token, ?string $userId = null, ?string $refreshToken = null, array $scopes = [], string $provider = 'facebook'): void
    {
        $registry = \Core\Drivers\DriverFactory::getRegistry();
        
        foreach ($registry as $channel => $config) {
            $driverClass = $config['driver'];
            if (class_exists($driverClass) && $driverClass::getCommonConfigKey() === $provider) {
                $driverClass::storeCredentials([
                    'access_token' => $token,
                    'refresh_token' => $refreshToken,
                    'user_id' => $userId,
                    'scopes' => $scopes
                ]);
                return;
            }
        }
    }
}
