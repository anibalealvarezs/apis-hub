<?php

namespace Enums;

/**
 * Constants for the different types of digital assets that can be associated with a Page entity.
 */
class PageType
{
    public const FACEBOOK_PAGE = 'facebook_page';
    public const WEBSITE = 'website';
    public const TWITTER_PROFILE = 'twitter_profile';
    public const LINKEDIN_PAGE = 'linkedin_page';
    public const TIKTOK_PROFILE = 'tiktok_profile';
    public const PINTEREST_PROFILE = 'pinterest_profile';
    public const GOOGLE_BUSINESS = 'google_business';
    public const SHOPIFY_STORE = 'shopify_store';
    public const KLAVIYO_ACCOUNT = 'klaviyo_account';
    public const BIGCOMMERCE_STORE = 'bigcommerce_store';

    /**
     * Get the identifying prefix for a given type.
     * Delegates to AssetRegistry for dynamic lookup.
     *
     * @param string $type
     * @return string|null
     */
    public static function getPrefix(string $type): ?string
    {
        $pattern = \Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::findByType($type);

        return $pattern['prefix'] ?? null;
    }

    /**
     * Get all registered types.
     *
     * @return array
     */
    public static function getAll(): array
    {
        return array_keys(\Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::getAll());
    }
}
