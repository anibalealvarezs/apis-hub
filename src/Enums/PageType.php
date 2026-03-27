<?php

namespace Enums;

/**
 * Constants for the different types of digital assets that can be associated with a Page entity.
 */
enum PageType: string
{
    case FACEBOOK_PAGE = 'facebook_page';
    case WEBSITE = 'website';
    case TWITTER_PROFILE = 'twitter_profile';
    case LINKEDIN_PAGE = 'linkedin_page';
    case TIKTOK_PROFILE = 'tiktok_profile';
    case GOOGLE_BUSINESS = 'google_business';

    /**
     * @return string
     */
    public function getPrefix(): string
    {
        return match ($this) {
            self::FACEBOOK_PAGE => 'fb:page',
            self::WEBSITE => 'web:site',
            self::TWITTER_PROFILE => 'tw:profile',
            self::LINKEDIN_PAGE => 'li:page',
            self::TIKTOK_PROFILE => 'tk:profile',
            self::GOOGLE_BUSINESS => 'gb:location',
        };
    }
}
