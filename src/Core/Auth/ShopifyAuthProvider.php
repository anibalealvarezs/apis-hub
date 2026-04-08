<?php

namespace Core\Auth;

use Interfaces\AuthProviderInterface;
use Helpers\Helpers;

class ShopifyAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['SHOPIFY_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/shopify_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            // We use 'shopify_auth' as our unified key
            $this->credentials = $tokens['shopify_auth'] ?? [];
        }

        // Fallback to Channels Config / ENV if JSON is empty (for legacy/static setups)
        if (empty($this->credentials)) {
            $config = Helpers::getChannelsConfig()['shopify'] ?? [];
            $this->credentials = [
                'access_token' => $config['shopify_api_key'] ?? $_ENV['SHOPIFY_API_KEY'] ?? '',
                'shop_name' => $config['shopify_shop_name'] ?? $_ENV['SHOPIFY_SHOP_NAME'] ?? '',
                'version' => $config['shopify_last_stable_revision'] ?? $_ENV['SHOPIFY_API_VERSION'] ?? '2024-01',
            ];
        }
    }

    public function getAccessToken(): string
    {
        return $this->credentials['access_token'] ?? '';
    }

    public function getShopName(): string
    {
        return $this->credentials['shop_name'] ?? '';
    }

    public function getVersion(): string
    {
        return $this->credentials['version'] ?? '2024-01';
    }

    public function isValid(): bool
    {
        return !empty($this->getAccessToken()) && !empty($this->getShopName());
    }

    public function isExpired(): bool
    {
        // Shopify offline access tokens for private/custom apps don't usually expire
        return false;
    }

    public function refresh(): bool
    {
        // For private apps, refresh is manual rotate. For OAuth, we'd implement it here.
        return false;
    }

    public function getScopes(): array
    {
        return $this->credentials['scopes'] ?? [];
    }

    public function setAccessToken(string $token, string $shopName, ?string $version = null): void
    {
        $this->credentials['access_token'] = $token;
        $this->credentials['shop_name'] = $shopName;
        if ($version) $this->credentials['version'] = $version;
        $this->saveCredentials();
    }

    private function saveCredentials(): void
    {
        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['shopify_auth'] = array_merge($tokens['shopify_auth'] ?? [], $this->credentials);
        $tokens['shopify_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
