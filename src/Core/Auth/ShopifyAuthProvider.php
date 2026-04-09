<?php

declare(strict_types=1);

namespace Core\Auth;

class ShopifyAuthProvider extends BaseAuthProvider
{
    public function getAccessToken(): string
    {
        return $this->data['shopify_auth']['access_token'] ?? "";
    }

    public function getShopName(): string
    {
        return $this->data['shopify_auth']['shop_name'] ?? "";
    }

    public function getVersion(): string
    {
        return $this->data['shopify_auth']['version'] ?? "2024-04";
    }

    public function setAccessToken(string $token, string $shopName = "", string $version = ""): void
    {
        if (!isset($this->data['shopify_auth'])) {
            $this->data['shopify_auth'] = [];
        }
        $this->data['shopify_auth']['access_token'] = $token;
        if ($shopName) {
            $this->data['shopify_auth']['shop_name'] = $shopName;
        }
        if ($version) {
            $this->data['shopify_auth']['version'] = $version;
        }
        $this->save();
    }
}
