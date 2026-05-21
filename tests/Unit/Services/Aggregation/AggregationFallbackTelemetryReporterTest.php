<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Services\Aggregation\AggregationFallbackTelemetryReporter;
    use Tests\Unit\BaseUnitTestCase;

    final class AggregationFallbackTelemetryReporterTest extends BaseUnitTestCase
    {
        public function testSummarizesMissingProfileCapabilityByPilotSegmentation(): void
        {
            $reporter = new AggregationFallbackTelemetryReporter();

            $summary = $reporter->summarize(
                events: [
                    [
                        'executor_path_decision'   => 'legacy',
                        'executor_fallback_reason' => 'missing_profile_capability',
                        'planner_diagnostics'      => [
                            'profile_channel'   => 'google_search_console',
                            'profile_checked'   => true,
                            'profile_supported' => false,
                        ],
                    ],
                    [
                        'executor_path_decision'   => 'legacy',
                        'executor_fallback_reason' => 'missing_profile_capability',
                        'planner_diagnostics'      => [
                            'profile_channel'   => 'facebook_organic',
                            'profile_checked'   => true,
                            'profile_supported' => false,
                        ],
                    ],
                    [
                        'executor_path_decision'   => 'legacy',
                        'executor_fallback_reason' => 'unsupported_group_pattern',
                        'planner_diagnostics'      => [
                            'profile_channel'   => 'shopify',
                            'profile_checked'   => false,
                            'profile_supported' => true,
                        ],
                    ],
                    [
                        'executor_path_decision' => 'optimized',
                        'planner_diagnostics'    => [
                            'profile_channel'   => 'google_search_console',
                            'profile_checked'   => true,
                            'profile_supported' => true,
                        ],
                    ],
                ],
                pilotChannels: ['google_search_console', 'facebook_organic']
            );

            $this->assertSame(4, $summary['total_events']);
            $this->assertSame(3, $summary['legacy_events']);
            $this->assertSame(2, $summary['missing_profile_capability_events']);
            $this->assertSame(2, $summary['pilot_missing_profile_capability_events']);
            $this->assertSame(0, $summary['non_pilot_missing_profile_capability_events']);

            $this->assertSame(2, $summary['by_channel']['google_search_console']['events']);
            $this->assertSame(1, $summary['by_channel']['google_search_console']['missing_profile_capability_events']);
            $this->assertSame(1, $summary['by_channel']['facebook_organic']['missing_profile_capability_events']);
            $this->assertSame(0, $summary['by_channel']['shopify']['missing_profile_capability_events']);
        }

        public function testCountsUnknownChannelAsNonPilotForProfileFallbacks(): void
        {
            $reporter = new AggregationFallbackTelemetryReporter();

            $summary = $reporter->summarize(
                events: [
                    [
                        'executor_path_decision'   => 'legacy',
                        'executor_fallback_reason' => 'missing_profile_capability',
                        'planner_diagnostics'      => [
                            'profile_checked'   => true,
                            'profile_supported' => false,
                        ],
                    ],
                ],
                pilotChannels: ['google_search_console']
            );

            $this->assertSame(1, $summary['missing_profile_capability_events']);
            $this->assertSame(0, $summary['pilot_missing_profile_capability_events']);
            $this->assertSame(1, $summary['non_pilot_missing_profile_capability_events']);
            $this->assertSame(1, $summary['by_channel']['__unknown__']['missing_profile_capability_events']);
        }
    }

