<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileNormalizer;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
use Symfony\Component\Yaml\Yaml;

final class AggregationProfileResolver
{
    /** @var callable|null */
    private $aggregationProfilesResolver;

    /** @var callable|null */
    private $driverRegistryResolver;

    public function __construct(?callable $aggregationProfilesResolver = null, ?callable $driverRegistryResolver = null)
    {
        $this->aggregationProfilesResolver = $aggregationProfilesResolver;
        $this->driverRegistryResolver = $driverRegistryResolver;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolve(string $channel): array
    {
        if ($this->aggregationProfilesResolver !== null) {
            $profiles = call_user_func($this->aggregationProfilesResolver, $channel);
            return is_array($profiles) ? $profiles : [];
        }

        $registry = DriverFactory::getRegistry();
        if ($registry === []) {
            $registry = $this->resolveLocalDriverRegistry();
        }

        $driverClass = $registry[$channel]['driver'] ?? null;
        if (!is_string($driverClass) || !class_exists($driverClass)) {
            return [];
        }

        if (!is_subclass_of($driverClass, AggregationProfileProviderInterface::class)) {
            return [];
        }

        return AggregationProfileNormalizer::normalizeProfiles(
            defaultChannel: $channel,
            profiles: $driverClass::getAggregationProfiles()
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveLocalDriverRegistry(): array
    {
        if ($this->driverRegistryResolver !== null) {
            $resolved = call_user_func($this->driverRegistryResolver);
            return is_array($resolved) ? $resolved : [];
        }

        $file = dirname(__DIR__, 3) . '/config/drivers.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parseFile($file);

        return is_array($parsed) ? $parsed : [];
    }
}

