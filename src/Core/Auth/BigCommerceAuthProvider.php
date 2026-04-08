<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class BigCommerceAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['BIGCOMMERCE_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/bigcommerce_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['bigcommerce_auth'] ?? [];
        }

        // Fallback to Channels Config / ENV
        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['bigcommerce'] ?? [];
            $this->credentials = [
                'client_id' => $config['bigcommerce_client_id'] ?? $_ENV['BIGCOMMERCE_CLIENT_ID'] ?? '',
                'access_token' => $config['bigcommerce_access_token'] ?? $_ENV['BIGCOMMERCE_ACCESS_TOKEN'] ?? '',
                'store_hash' => $config['bigcommerce_store_hash'] ?? $_ENV['BIGCOMMERCE_STORE_HASH'] ?? '',
            ];
        }
    }

    public function getAccessToken(): string
    {
        return $this->credentials['access_token'] ?? '';
    }

    public function isValid(): bool
    {
        return !empty($this->credentials['access_token']) && !empty($this->credentials['store_hash']);
    }

    public function isExpired(): bool
    {
        return false;
    }

    public function refresh(): bool
    {
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
        $tokens['bigcommerce_auth'] = $this->credentials;
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
