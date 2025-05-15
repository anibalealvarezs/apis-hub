<?php

namespace Enums;

enum Channels: int
{
    /**
     * @var int
     */
    case shopify = 1;

    /**
     * @var int
     */
    case klaviyo = 2;

    /**
     * @var int
     */
    case facebook = 3;

    /**
     * @var int
     */
    case bigcommerce = 4;

    /**
     * @var int
     */
    case netsuite = 5;

    /**
     * @var int
     */
    case amazon = 6;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getCommonName(): string
    {
        return match($this) {
            self::shopify => 'Shopify',
            self::klaviyo => 'Klaviyo',
            self::facebook => 'Facebook',
            self::bigcommerce => 'BigCommerce',
            self::netsuite => 'Netsuite',
            self::amazon => 'Amazon',
        };
    }

    /**
     * Map a string channel name to an enum instance.
     *
     * @param string $name
     * @return ?self
     */
    public static function tryFromName(string $name): ?self
    {
        return match (strtolower($name)) {
            'shopify' => self::shopify,
            'klaviyo' => self::klaviyo,
            'facebook' => self::facebook,
            'bigcommerce' => self::bigcommerce,
            'netsuite' => self::netsuite,
            'amazon' => self::amazon,
            default => null,
        };
    }
}
