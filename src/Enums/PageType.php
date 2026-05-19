<?php

    namespace Enums;

    use Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry;
    use Anibalealvarezs\ApiDriverCore\Classes\PageTypeRegistry;

    /**
     * Constants for the different types of digital assets that can be associated with a Page entity.
     */
    class PageType
    {
        /**
         * Get the identifying prefix for a given type.
         * Delegates to AssetRegistry for dynamic lookup.
         *
         * @param string $type
         * @return string|null
         */
        public static function getPrefix(string $type): ?string
        {
            $pattern = AssetRegistry::findByType($type);

            return $pattern['prefix'] ?? null;
        }

        /**
         * Get all registered types.
         *
         * @return array [key => label]
         */
        public static function getAll(): array
        {
            return PageTypeRegistry::getAll();
        }
    }
