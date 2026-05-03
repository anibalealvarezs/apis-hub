<?php

declare(strict_types=1);

namespace Services;

use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileNormalizer;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\MetricConfig;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

class MetricProfileIndexPlanner
{
    private const FIELD_TO_COLUMN = [
        'id' => 'id',
        'channel' => 'channel',
        'name' => 'name',
        'period' => 'period',
        'account' => 'account_id',
        'channeledAccount' => 'channeled_account_id',
        'campaign' => 'campaign_id',
        'channeledCampaign' => 'channeled_campaign_id',
        'channeledAdGroup' => 'channeled_ad_group_id',
        'channeledAd' => 'channeled_ad_id',
        'creative' => 'creative_id',
        'page' => 'page_id',
        'query' => 'query_id',
        'post' => 'post_id',
        'product' => 'product_id',
        'customer' => 'customer_id',
        'order' => 'order_id',
        'country' => 'country_id',
        'device' => 'device_id',
        'dimensionSet' => 'dimension_set_id',
    ];

    /**
     * @return array<string, mixed>
     */
    public function plan(?string $channel = null): array
    {
        $profilesByChannel = $this->collectProfiles(channel: $channel);
        $existingIndexes = $this->getExistingMetricConfigIndexes();
        $deduplicatedCandidates = [];
        $channels = [];

        foreach ($profilesByChannel as $channelKey => $profiles) {
            $plannedProfiles = [];

            foreach ($profiles as $profile) {
                $plannedIndexes = [];
                foreach (($profile['metric_config']['index_hints'] ?? []) as $position => $hint) {
                    $candidate = $this->buildCandidate(
                        channel: $channelKey,
                        profileKey: (string)$profile['key'],
                        hint: $hint,
                        position: $position,
                        existingIndexes: $existingIndexes,
                    );
                    $plannedIndexes[] = $candidate;

                    $signature = implode('|', $candidate['columns']);
                    if (!isset($deduplicatedCandidates[$signature])) {
                        $deduplicatedCandidates[$signature] = [
                            'columns' => $candidate['columns'],
                            'semantic_columns' => $candidate['semantic_columns'],
                            'status' => $candidate['status'],
                            'matching_index' => $candidate['matching_index'],
                            'source_profiles' => [],
                        ];
                    }

                    $deduplicatedCandidates[$signature]['source_profiles'][] = $channelKey . ':' . $profile['key'];
                    if ($deduplicatedCandidates[$signature]['status'] !== 'exact_match' && $candidate['status'] === 'exact_match') {
                        $deduplicatedCandidates[$signature]['status'] = 'exact_match';
                        $deduplicatedCandidates[$signature]['matching_index'] = $candidate['matching_index'];
                    }
                }

                $plannedProfiles[] = [
                    'key' => $profile['key'],
                    'label' => $profile['label'],
                    'metric_config' => $profile['metric_config'],
                    'planned_indexes' => $plannedIndexes,
                ];
            }

            $channels[$channelKey] = [
                'profiles' => $plannedProfiles,
            ];
        }

        foreach ($deduplicatedCandidates as &$candidate) {
            $candidate['source_profiles'] = array_values(array_unique($candidate['source_profiles']));
        }
        unset($candidate);

        return [
            'channels' => $channels,
            'deduplicated_candidates' => array_values($deduplicatedCandidates),
            'existing_metric_config_indexes' => $existingIndexes,
            'field_column_map' => self::FIELD_TO_COLUMN,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function collectProfiles(?string $channel = null): array
    {
        $profilesByChannel = [];
        $registry = DriverFactory::getRegistry();
        if ($registry === []) {
            $registry = $this->loadLocalDriverRegistry();
        }

        foreach ($registry as $registeredChannel => $config) {
            if ($channel !== null && $registeredChannel !== $channel) {
                continue;
            }

            $driverClass = $config['driver'] ?? null;
            if (!is_string($driverClass) || !class_exists($driverClass)) {
                continue;
            }

            if (!is_subclass_of($driverClass, MetricProfileProviderInterface::class)) {
                continue;
            }

            $profilesByChannel[$registeredChannel] = MetricProfileNormalizer::normalizeProfiles(
                defaultChannel: $registeredChannel,
                profiles: $driverClass::getMetricProfiles(),
            );
        }

        ksort($profilesByChannel);

        return $profilesByChannel;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadLocalDriverRegistry(): array
    {
        $file = dirname(__DIR__, 2) . '/config/drivers.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parseFile($file);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<int, string> $hint
     * @param array<int, array<string, mixed>> $existingIndexes
     * @return array<string, mixed>
     */
    private function buildCandidate(string $channel, string $profileKey, array $hint, int $position, array $existingIndexes): array
    {
        $columns = [];
        $unmappedFields = [];

        foreach ($hint as $field) {
            if (!isset(self::FIELD_TO_COLUMN[$field])) {
                $unmappedFields[] = $field;
                continue;
            }

            $columns[] = self::FIELD_TO_COLUMN[$field];
        }

        $coverage = $this->resolveCoverage(columns: $columns, existingIndexes: $existingIndexes);

        return [
            'candidate_name' => sprintf('idx_metric_configs_profile_%s_%02d', $this->sanitizeKey($profileKey), $position + 1),
            'channel' => $channel,
            'profile_key' => $profileKey,
            'semantic_columns' => $hint,
            'columns' => $columns,
            'status' => $coverage['status'],
            'matching_index' => $coverage['matching_index'],
            'unmapped_fields' => $unmappedFields,
        ];
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $existingIndexes
     * @return array<string, string|null>
     */
    private function resolveCoverage(array $columns, array $existingIndexes): array
    {
        if ($columns === []) {
            return [
                'status' => 'invalid_candidate',
                'matching_index' => null,
            ];
        }

        foreach ($existingIndexes as $index) {
            $existingColumns = $index['columns'];
            if ($existingColumns === $columns) {
                return [
                    'status' => 'exact_match',
                    'matching_index' => $index['name'],
                ];
            }
        }

        foreach ($existingIndexes as $index) {
            $existingColumns = $index['columns'];
            if ($this->isLeftPrefix($columns, $existingColumns)) {
                return [
                    'status' => 'covered_by_existing_left_prefix',
                    'matching_index' => $index['name'],
                ];
            }
        }

        foreach ($existingIndexes as $index) {
            $existingColumns = $index['columns'];
            if ($this->isLeftPrefix($existingColumns, $columns)) {
                return [
                    'status' => 'extends_existing_left_prefix',
                    'matching_index' => $index['name'],
                ];
            }
        }

        return [
            'status' => 'new_candidate',
            'matching_index' => null,
        ];
    }

    /**
     * @param array<int, string> $prefix
     * @param array<int, string> $full
     */
    private function isLeftPrefix(array $prefix, array $full): bool
    {
        if (count($prefix) > count($full)) {
            return false;
        }

        foreach ($prefix as $index => $column) {
            if (($full[$index] ?? null) !== $column) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{name:string, columns:array<int, string>}>
     */
    private function getExistingMetricConfigIndexes(): array
    {
        $reflection = new ReflectionClass(MetricConfig::class);
        $indexes = [];

        foreach ($reflection->getAttributes(ORM\Index::class) as $attribute) {
            $arguments = $attribute->getArguments();
            $columns = $arguments['columns'] ?? [];
            $name = $arguments['name'] ?? implode('_', $columns);

            if (!is_array($columns) || $columns === []) {
                continue;
            }

            $indexes[] = [
                'name' => (string)$name,
                'columns' => array_values(array_map(static fn ($column) => (string)$column, $columns)),
            ];
        }

        usort($indexes, static fn (array $left, array $right) => strcmp($left['name'], $right['name']));

        return $indexes;
    }

    private function sanitizeKey(string $key): string
    {
        $sanitized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key));
        return trim($sanitized, '_');
    }
}


