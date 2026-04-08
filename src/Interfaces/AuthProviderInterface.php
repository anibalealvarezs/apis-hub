<?php

namespace Interfaces;

/**
 * Interface AuthProviderInterface
 * Defines the contract for an Authentication Provider (Google, Facebook, etc.)
 */
interface AuthProviderInterface
{
    /**
     * Get a valid access token. Should handle refreshes automatically.
     */
    public function getAccessToken(): string;

    /**
     * Check if the current tokens are present and usable.
     */
    public function isValid(): bool;

    /**
     * Force a token refresh.
     */
    public function refresh(): bool;

    /**
     * Get the list of scopes authorized for this provider.
     */
    public function getScopes(): array;
}
