<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class PinterestAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['PINTEREST_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/pinterest_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['pinterest_auth'] ?? [];
        }

        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['pinterest'] ?? [];
            $this->credentials = [
                'client_id' => $config['pinterest_client_id'] ?? $_ENV['PINTEREST_CLIENT_ID'] ?? '',
                'client_secret' => $config['pinterest_client_secret'] ?? $_ENV['PINTEREST_CLIENT_SECRET'] ?? '',
                'access_token' => $config['pinterest_access_token'] ?? $_ENV['PINTEREST_ACCESS_TOKEN'] ?? '',
                'refresh_token' => $config['pinterest_refresh_token'] ?? $_ENV['PINTEREST_REFRESH_TOKEN'] ?? '',
            ];
        }
    }

    public function getAccessToken(): string
    {
        return $this->credentials['access_token'] ?? '';
    }

    public function isValid(): bool
    {
        return !empty($this->getAccessToken());
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
        $tokens['pinterest_auth'] = $this->credentials;
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
