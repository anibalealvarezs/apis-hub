<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;

    final class AggregationEntityFieldResolver
    {
        /**
         * Returns the SQL expression to extract the platform entity ID from channeled_accounts.data
         */
        public function getChanneledAccountEntityIdExpr(string $channel, bool $isPostgres): string
        {
            $field = $this->resolvePlatformEntityIdField($channel);

            if ($isPostgres) {
                return "ca.data->>'$field'";
            }

            return "JSON_UNQUOTE(JSON_EXTRACT(ca.data, '$.$field'))";
        }

        /**
         * Resolves the field name from the driver registry
         */
        private function resolvePlatformEntityIdField(string $channel): string
        {
            $registry = DriverFactory::getRegistry();
            $driverClass = $registry[$channel]['driver'] ?? null;

            if (is_string($driverClass) 
                && class_exists($driverClass) 
                && is_subclass_of($driverClass, CanonicalMetricDictionaryProviderInterface::class)
            ) {
                return $driverClass::getPlatformEntityIdField();
            }

            return 'platform_id';
        }

        /**
         * Returns the SQL expression to extract the page platform ID, coalescing with channeled_account data if needed
         */
        public function getPagePlatformIdExpr(string $channel, bool $isPostgres): string
        {
            $caExpr = $this->getChanneledAccountEntityIdExpr($channel, $isPostgres);
            
            if ($isPostgres) {
                return "COALESCE(CAST(p.platform_id AS TEXT), $caExpr)";
            }

            return "COALESCE(CAST(p.platform_id AS CHAR), $caExpr)";
        }
    }
