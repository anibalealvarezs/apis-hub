<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class NetSuiteAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['NETSUITE_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/netsuite_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['netsuite_auth'] ?? [];
        }

        // Fallback to Channels Config / ENV
        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['netsuite'] ?? [];
            $this->credentials = [
                'consumer_id' => $config['netsuite_consumer_id'] ?? $_ENV['NETSUITE_CONSUMER_ID'] ?? '',
                'consumer_secret' => $config['netsuite_consumer_secret'] ?? $_ENV['NETSUITE_CONSUMER_SECRET'] ?? '',
                'token_id' => $config['netsuite_token_id'] ?? $_ENV['NETSUITE_TOKEN_ID'] ?? '',
                'token_secret' => $config['netsuite_token_secret'] ?? $_ENV['NETSUITE_TOKEN_SECRET'] ?? '',
                'account_id' => $config['netsuite_account_id'] ?? $_ENV['NETSUITE_ACCOUNT_ID'] ?? '',
                'store_base_url' => $config['netsuite_store_base_url'] ?? $_ENV['NETSUITE_STORE_BASE_URL'] ?? '',
            ];
        }
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function getAccessToken(): string
    {
        return $this->credentials['token_id'] ?? '';
    }

    public function isValid(): bool
    {
        return !empty($this->credentials['token_id']) && !empty($this->credentials['consumer_id']);
    }

    public function isExpired(): bool
    {
        // NetSuite TBA tokens are long-lived and don't expire typically
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
        $tokens['netsuite_auth'] = array_merge($tokens['netsuite_auth'] ?? [], $this->credentials);
        $tokens['netsuite_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
