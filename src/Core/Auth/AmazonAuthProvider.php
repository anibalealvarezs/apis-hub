<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class AmazonAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['AMAZON_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/amazon_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['amazon_auth'] ?? [];
        }

        // Fallback to Channels Config / ENV
        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['amazon'] ?? [];
            $this->credentials = [
                'client_id' => $config['amazon_client_id'] ?? $_ENV['AMAZON_CLIENT_ID'] ?? '',
                'client_secret' => $config['amazon_client_secret'] ?? $_ENV['AMAZON_CLIENT_SECRET'] ?? '',
                'refresh_token' => $config['amazon_refresh_token'] ?? $_ENV['AMAZON_REFRESH_TOKEN'] ?? '',
                'marketplace_id' => $config['amazon_marketplace_id'] ?? $_ENV['AMAZON_MARKPLACE_ID'] ?? '',
                'seller_id' => $config['amazon_seller_id'] ?? $_ENV['AMAZON_SELLER_ID'] ?? '',
            ];
        }
    }

    public function getAccessToken(): string
    {
        return $this->credentials['access_token'] ?? '';
    }

    public function isValid(): bool
    {
        return !empty($this->credentials['refresh_token']) || !empty($this->credentials['client_id']);
    }

    public function isExpired(): bool
    {
        return true; // Force refresh if we have a refresh token logic
    }

    public function refresh(): bool
    {
        // Placeholder for LWA (Login with Amazon) refresh logic
        return false;
    }

    public function getScopes(): array
    {
        return [];
    }

    public function setAuthProvider(AuthProviderInterface $provider): void {}

    public function setCredentials(array $data): void
    {
        $this->credentials = array_merge($this->credentials, $data);
        $this->saveCredentials();
    }

    private function saveCredentials(): void
    {
        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['amazon_auth'] = $this->credentials;
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
