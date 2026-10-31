<?php

namespace Services\Analytics;

use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Doctrine\DBAL\Connection;

final class ScopedReportService
{
    public function __construct(private ScopedReportStore $store, private Connection $connection, private $providerResolver = null) {}

    public function catalog(string $channel, int $account, string $actor, bool $refresh = false): array
    {
        $source = $this->source($channel, $account);
        $key = hash('sha256', 'catalog:'.$channel.':'.$account);
        if ($refresh) $this->store->expireReady($key);
        $record = $this->store->enqueue($key, ['operation' => 'catalog', 'channel' => $channel, 'account' => $account, 'authorization_scope' => $actor], 86400);
        $result = $record['result'] ?? $record['last_good'] ?? null;
        $catalog = $result['catalog'] ?? null;
        if ($catalog) {
            $windows = [];
            foreach ($this->store->records() as $cached) {
                $request = $cached['request'] ?? [];
                if (($request['operation'] ?? '') !== 'report' || ($request['account'] ?? null) !== $account || ($request['channel'] ?? '') !== $channel || ($request['authorization_scope'] ?? '') !== $actor || ($cached['state'] ?? '') !== 'ready' || ($cached['expires_at'] ?? 0) <= $this->store->now()) continue;
                $definition = $request['definition'];
                $windows[] = ['report_id' => $cached['key'], 'kind' => $definition['kind'], 'date_ranges' => $definition['payload']['dateRanges'] ?? $definition['payload']['cohortSpec']['cohorts'] ?? [], 'expires_at' => $cached['expires_at']];
                foreach ($definition['field_definitions'] as $kind => $fields) foreach ($fields as $name => $_) {
                    if (isset($catalog[$kind][$name])) $catalog[$kind][$name]['cache_coverage'] = 'available_for_specific_completed_definitions_only';
                }
            }
            $catalog['completed_windows'] = array_slice($windows, -50);
        }
        return [
            'state' => $record['state'], 'catalog' => $catalog,
            'last_updated' => $record['updated_at'], 'error' => $record['error'] ?? null,
            'permission_state' => $record['state'] === 'failed' ? 'unavailable' : 'not_verified_by_http_read',
        ];
    }

    public function query(string $channel, int $account, array $definition, string $actor): array
    {
        $source = $this->source($channel, $account);
        $catalog = $this->store->get(hash('sha256', 'catalog:'.$channel.':'.$account));
        $snapshot = $catalog['result'] ?? $catalog['last_good'] ?? null;
        if (!$snapshot) return ['state' => 'catalog_pending', 'catalog' => $this->catalog($channel, $account, $actor)];
        $provider = $this->provider($channel);
        if (!method_exists($provider, 'compileAnalyticsDefinition')) throw new \InvalidArgumentException('This driver does not support scoped reports.');
        $context = array_replace($snapshot['catalog']['context'], ['authorization_scope' => $actor]);
        if ($context['property_id'] !== (string)$source['platform_id']) throw new \RuntimeException('Property identity changed. Refresh its catalog.');
        $frozen = $provider::compileAnalyticsDefinition($definition, $snapshot['metadata'], $context);
        $key = $frozen['hash'];
        $record = $this->store->enqueue($key, ['operation' => 'report', 'channel' => $channel, 'account' => $account, 'definition' => $frozen, 'authorization_scope' => $actor], 3600);
        return [
            'state' => $record['state'], 'report_id' => $key, 'definition' => $frozen,
            'last_updated' => $record['updated_at'], 'expires_at' => $record['expires_at'] ?? null,
            'result' => $record['state'] === 'ready' ? $record['result'] : null,
            'stale_result' => $record['last_good'] ?? null, 'error' => $record['error'] ?? null,
            'coverage' => $record['state'] === 'ready' ? 'complete_source_response_for_definition' : 'not_ready',
        ];
    }

    public function process(int $limit = 3): array
    {
        return $this->store->process(function ($request) {
            $source = $this->source($request['channel'], (int)$request['account']);
            $provider = $this->provider($request['channel']);
            $config = $this->configuration($request['channel']) + ['platform_id' => (string)$source['platform_id']];
            // Explicit per-task identity overrides any ambient account selection.
            $config['platform_id'] = $config['account_id'] = (string)$source['platform_id'];
            if (isset($config['enabled']) && !$config['enabled']) throw new \InvalidArgumentException('Source syncing is disabled for this channel.');
            foreach ($config['properties'] ?? [] as $property) {
                if ((string)($property['platformId'] ?? '') === (string)$source['platform_id'] && (empty($property['enabled']) || !empty($property['lost_access']))) throw new \InvalidArgumentException('Source syncing is disabled or access was lost for this property.');
            }
            if ($request['operation'] === 'catalog') return $provider->fetchAnalyticsCatalog($config);
            $response = $provider->executeAnalyticsReport($config, $request['definition']);
            $delay = $response['_source_delay_seconds'] ?? 0;
            unset($response['_source_delay_seconds']);
            return ['response' => $response, 'source_delay_seconds' => $delay, 'quality' => self::quality($response['metadata'] ?? []), 'completed_at' => $this->store->now()];
        }, $limit);
    }

    private function source(string $channel, int $account): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $channel) || $account < 1) throw new \InvalidArgumentException('Select a valid channel account.');
        $source = $this->connection->fetchAssociative('SELECT ca.id, ca.platform_id FROM channeled_accounts ca JOIN channels c ON c.id = ca.channel WHERE ca.id = ? AND c.name = ?', [$account, $channel]);
        if (!$source) throw new \InvalidArgumentException('The selected account does not belong to this channel.');
        return $source;
    }

    private function provider(string $channel): object
    {
        $provider = $this->providerResolver ? ($this->providerResolver)($channel) : DriverFactory::get($channel);
        if (!method_exists($provider, 'fetchAnalyticsCatalog') || !method_exists($provider, 'executeAnalyticsReport')) throw new \InvalidArgumentException('Update this channel driver to use scoped analytics reports.');
        return $provider;
    }

    private function configuration(string $channel): array
    {
        if ($this->providerResolver) return [];
        $configs = \Helpers\Helpers::getChannelsConfig();
        $class = $this->provider($channel)::class;
        $common = $class::getCommonConfigKey();
        return array_replace($configs[$common] ?? [], $configs[$channel] ?? []);
    }

    public static function quality(array $metadata): array
    {
        $keys = ['subjectToThresholding', 'dataLossFromOtherRow', 'samplingMetadatas', 'currencyCode', 'timeZone', 'emptyReason', 'schemaRestrictionResponse'];
        $quality = array_intersect_key($metadata, array_flip($keys));
        return ['state' => $metadata ? 'source_metadata_available' : 'unknown', 'details' => $quality];
    }
}
