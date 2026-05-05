<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class AggregationFallbackTelemetryReporter
{
    private const CHANNEL_UNKNOWN = '__unknown__';

    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<int, string> $pilotChannels
     * @return array<string, mixed>
     */
    public function summarize(array $events, array $pilotChannels = []): array
    {
        $pilotLookup = [];
        foreach ($pilotChannels as $channel) {
            $normalized = $this->normalizeChannel($channel);
            if ($normalized !== null) {
                $pilotLookup[$normalized] = true;
            }
        }

        $summary = [
            'total_events' => 0,
            'legacy_events' => 0,
            'missing_profile_capability_events' => 0,
            'pilot_missing_profile_capability_events' => 0,
            'non_pilot_missing_profile_capability_events' => 0,
            'by_channel' => [],
        ];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $summary['total_events']++;

            $pathDecision = strtolower((string)($event['executor_path_decision'] ?? ''));
            if ($pathDecision === 'legacy') {
                $summary['legacy_events']++;
            }

            $diagnostics = $event['planner_diagnostics'] ?? [];
            $channel = $this->extractChannel($diagnostics);
            $channelBucket = $channel ?? self::CHANNEL_UNKNOWN;

            if (!isset($summary['by_channel'][$channelBucket])) {
                $summary['by_channel'][$channelBucket] = [
                    'events' => 0,
                    'legacy_events' => 0,
                    'missing_profile_capability_events' => 0,
                    'profile_checked_true_events' => 0,
                    'profile_supported_false_events' => 0,
                ];
            }

            $summary['by_channel'][$channelBucket]['events']++;
            if ($pathDecision === 'legacy') {
                $summary['by_channel'][$channelBucket]['legacy_events']++;
            }

            if (is_array($diagnostics) && (($diagnostics['profile_checked'] ?? null) === true)) {
                $summary['by_channel'][$channelBucket]['profile_checked_true_events']++;
            }

            if (is_array($diagnostics) && (($diagnostics['profile_supported'] ?? null) === false)) {
                $summary['by_channel'][$channelBucket]['profile_supported_false_events']++;
            }

            $fallback = strtolower(trim((string)($event['executor_fallback_reason'] ?? $event['fallback_reason'] ?? '')));
            if ($fallback !== 'missing_profile_capability') {
                continue;
            }

            $summary['missing_profile_capability_events']++;
            $summary['by_channel'][$channelBucket]['missing_profile_capability_events']++;

            if ($channel !== null && isset($pilotLookup[$channel])) {
                $summary['pilot_missing_profile_capability_events']++;
            } else {
                $summary['non_pilot_missing_profile_capability_events']++;
            }
        }

        ksort($summary['by_channel']);

        return $summary;
    }

    /**
     * @param array<string, mixed>|mixed $diagnostics
     */
    private function extractChannel(mixed $diagnostics): ?string
    {
        if (!is_array($diagnostics)) {
            return null;
        }

        return $this->normalizeChannel($diagnostics['profile_channel'] ?? null);
    }

    private function normalizeChannel(mixed $channel): ?string
    {
        if (!is_scalar($channel)) {
            return null;
        }

        $normalized = strtolower(trim((string)$channel));
        return $normalized !== '' ? $normalized : null;
    }
}

