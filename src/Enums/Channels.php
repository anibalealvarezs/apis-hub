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
}
