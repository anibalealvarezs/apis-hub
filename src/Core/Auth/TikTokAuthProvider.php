<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class TikTokAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['TIKTOK_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/tiktok_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['tiktok_auth'] ?? [];
        }

        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['tiktok'] ?? [];
            $this->credentials = [
                'client_key' => $config['tiktok_client_key'] ?? $_ENV['TIKTOK_CLIENT_KEY'] ?? '',
                'client_secret' => $config['tiktok_client_secret'] ?? $_ENV['TIKTOK_CLIENT_SECRET'] ?? '',
                'access_token' => $config['tiktok_access_token'] ?? $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '',
                'refresh_token' => $config['tiktok_refresh_token'] ?? $_ENV['TIKTOK_REFRESH_TOKEN'] ?? '',
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
        $tokens['tiktok_auth'] = $this->credentials;
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
