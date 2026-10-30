<?php

namespace Classes\Upgrades\Routines;

use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileNormalizer;
use Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi;
use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_18_3_to_v1_18_4 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array { return ['1.18.3']; }
    public function getToVersion(): string { return '1.18.4'; }
    public function requiresNuclearResync(): bool { return false; }

    public function getDescription(): string
    {
        return 'Enables corrected GA4 reporting and updated channel drivers without rewriting cached metric identities.';
    }

    protected function runtimeCapabilities(): array
    {
        $contract = ['version' => 1];
        $normalized = AggregationProfileNormalizer::normalizeProfile('release_probe', ['query_contract' => $contract]);
        $driverContract = method_exists(GoogleAnalyticsDriver::class, 'getAnalyticsQueryContract')
            ? GoogleAnalyticsDriver::getAnalyticsQueryContract() : [];

        return [
            'driver query-contract preservation' => ($normalized['query_contract'] ?? null) === $contract,
            'GA4 query contract v1' => ($driverContract['version'] ?? null) === 1,
            'GA4 source compatibility checks' => method_exists(AnalyticsDataApi::class, 'checkCompatibility'),
        ];
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $missing = array_keys(array_filter($this->runtimeCapabilities(), static fn ($supported) => $supported !== true));
        if ($missing !== []) {
            throw new \RuntimeException('Missing v1.18.4 capabilities: '.implode(', ', $missing).'. Install the locked release dependencies before retrying.');
        }
        $output->writeln('   <info>[1/1]</info> Verified GA4 reporting dependencies for v1.18.4. No cached data rewrite or automatic resync is required.');
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln('   <info>[1/1]</info> v1.18.4 has no stored-data changes to revert.');
    }
}
