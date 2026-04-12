<?php

namespace Enums;

/**
 * Constants for the different types of digital accounts that can be associated with a ChanneledAccount.
 */
class Account
{
    /**
     * Get all registered types.
     *
     * @return array [key => label]
     */
    public static function getAll(): array
    {
        return \Anibalealvarezs\ApiDriverCore\Classes\AccountTypeRegistry::getAll();
    }
}
