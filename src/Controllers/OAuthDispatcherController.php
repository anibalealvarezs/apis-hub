<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Anibalealvarezs\ApiSkeleton\Interfaces\OAuthProviderInterface;
use Classes\DriverInitializer;

class OAuthDispatcherController
{
    /**
     * Start the OAuth flow for a given channel.
     * 
     * @param Request $request
     * @param string $channel
     * @return Response
     */
    public function start(Request $request, string $channel): Response
    {
        try {
            $driver = DriverFactory::get($channel);
            $authProvider = $driver->getAuthProvider();

            if (!$authProvider instanceof OAuthProviderInterface) {
                return new Response("Provider '$channel' does not support OAuth authentication.", 400);
            }

            // Get configuration for scope calculation (dynamic based on modular config)
            $config = DriverInitializer::validateConfig($channel);
            
            $redirectUri = $this->getRedirectUri($request, $channel);
            $authUrl = $authProvider->getAuthUrl($redirectUri, $config);

            return new RedirectResponse($authUrl);
        } catch (\Exception $e) {
            return new Response("Authentication Start Error: " . $e->getMessage(), 500);
        }
    }

    /**
     * Handle the OAuth callback for a given channel.
     * 
     * @param Request $request
     * @param string $channel
     * @return Response
     */
    public function callback(Request $request, string $channel): Response
    {
        $code = $request->query->get('code');
        if (!$code) {
            return new Response("Authorization code missing from redirect.", 400);
        }

        try {
            $driver = DriverFactory::get($channel);
            $authProvider = $driver->getAuthProvider();

            if (!$authProvider instanceof OAuthProviderInterface) {
                return new Response("Provider '$channel' does not support OAuth callbacks.", 400);
            }

            $redirectUri = $this->getRedirectUri($request, $channel);
            
            // Exchange code for tokens
            $credentials = $authProvider->handleCallback($code, $redirectUri);

            // Persist credentials to the provider's storage
            $authProvider->updateCredentials($credentials);

            return $this->renderSuccessView($channel);
        } catch (\Exception $e) {
            return new Response("Callback Error: " . $e->getMessage(), 500);
        }
    }

    /**
     * Generate a standardized redirect URI for the given channel.
     * 
     * @param Request $request
     * @param string $channel
     * @return string
     */
    private function getRedirectUri(Request $request, string $channel): string
    {
        $protocol = $request->isSecure() ? 'https' : 'http';
        
        // Handle proxies (e.g. Nginx, Docker)
        if ($request->headers->get('X-Forwarded-Proto') === 'https') {
            $protocol = 'https';
        }

        $host = $request->getHttpHost();

        if (!str_contains($host, 'localhost') && !str_contains($host, '127.0.0.1')) {
            $protocol = 'https';
        }

        return "$protocol://$host/auth/callback/$channel";
    }

    /**
     * Render a pretty success message.
     * 
     * @param string $channel
     * @return Response
     */
    private function renderSuccessView(string $channel): Response
    {
        $channelName = ucwords(str_replace(['_', '-'], ' ', $channel));
        
        $html = "
            <div style='background: #0a0c10; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: sans-serif; text-align: center; padding: 20px;'>
                <div style='background: #161b22; border: 1px solid #30363d; padding: 40px; border-radius: 20px; max-width: 400px;'>
                    <h1 style='color: #238636;'>✓ Success!</h1>
                    <p style='color: #8b949e;'>Your $channelName credentials have been successfully updated and stored in APIs Hub.</p>
                    <a href='/' style='display: inline-block; margin-top: 20px; background: #58a6ff; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none;'>Back to Dashboard</a>
                </div>
            </div>
        ";

        return new Response($html);
    }
}
