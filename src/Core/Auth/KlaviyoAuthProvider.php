<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class KlaviyoAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['KLAVIYO_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/klaviyo_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['klaviyo_auth'] ?? [];
        }

        // Fallback to Channels Config / ENV
        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['klaviyo'] ?? [];
            $this->credentials = [
                'access_token' => $config['klaviyo_api_key'] ?? $_ENV['KLAVIYO_API_KEY'] ?? '',
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
        // Klaviyo API Keys don't usually expire like OAuth tokens
        return false;
    }

    public function refresh(): bool
    {
        return false;
    }

    public function getScopes(): array
    {
        return $this->credentials['scopes'] ?? [];
    }

    public function setAuthProvider(AuthProviderInterface $provider): void 
    {
        // Not needed for the provider itself
    }

    public function setAccessToken(string $token): void
    {
        $this->credentials['access_token'] = $token;
        $this->saveCredentials();
    }

    private function saveCredentials(): void
    {
        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['klaviyo_auth'] = array_merge($tokens['klaviyo_auth'] ?? [], $this->credentials);
        $tokens['klaviyo_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
