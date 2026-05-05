<?php

    declare(strict_types=1);

    namespace Tests\Unit\Commands\Analytics;

    use Commands\Analytics\ReportAggregationTelemetryCommand;
    use PHPUnit\Framework\TestCase;
    use Traits\AggregationFallbackTelemetryReporter;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Tester\CommandTester;

    final class ReportAggregationTelemetryCommandTest extends TestCase
    {
        private string $inputFile;
        private string $outputFile;

        protected function setUp(): void
        {
            parent::setUp();
            $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aggregation-telemetry-'.bin2hex(random_bytes(6));
            $this->inputFile = $base.'.json';
            $this->outputFile = $base.'.summary.json';
        }

        protected function tearDown(): void
        {
            @unlink($this->inputFile);
            @unlink($this->outputFile);
            parent::tearDown();
        }

        public function testOutputsPrettyPrintedSummaryAndWritesFile(): void
        {
            file_put_contents($this->inputFile, json_encode([
                'pilot_channels' => ['google_search_console'],
                'events'         => [
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
                        'executor_path_decision' => 'optimized',
                        'planner_diagnostics'    => [
                            'profile_channel'   => 'shopify',
                            'profile_checked'   => false,
                            'profile_supported' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            $command = new ReportAggregationTelemetryCommand(new AggregationFallbackTelemetryReporter());
            $tester = new CommandTester($command);

            $tester->execute([
                '--input'         => $this->inputFile,
                '--output'        => $this->outputFile,
                '--pilot-channel' => ['facebook_organic'],
                '--pretty'        => true,
            ]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
            $display = $tester->getDisplay();
            $this->assertStringContainsString('"missing_profile_capability_events": 1', $display);
            $this->assertStringContainsString('"pilot_missing_profile_capability_events": 1', $display);
            $this->assertStringContainsString($this->outputFile, $display);

            $written = file_get_contents($this->outputFile);
            $this->assertNotFalse($written);
            $this->assertStringContainsString('"google_search_console"', (string)$written);
        }

        public function testFailsWhenInputJsonIsInvalid(): void
        {
            file_put_contents($this->inputFile, '{invalid json');

            $command = new ReportAggregationTelemetryCommand();
            $tester = new CommandTester($command);
            $tester->execute([
                '--input' => $this->inputFile,
            ]);

            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertStringContainsString('Syntax error', $tester->getDisplay());
        }

        public function testReadsNdjsonTelemetryPayloads(): void
        {
            file_put_contents(
                $this->inputFile,
                implode(PHP_EOL, [
                    json_encode([
                        'executor_path_decision'   => 'legacy',
                        'executor_fallback_reason' => 'missing_profile_capability',
                        'planner_diagnostics'      => [
                            'profile_channel'   => 'google_search_console',
                            'profile_checked'   => true,
                            'profile_supported' => false,
                        ],
                    ], JSON_THROW_ON_ERROR),
                    json_encode([
                        'executor_path_decision' => 'optimized',
                        'planner_diagnostics'    => [
                            'profile_channel'   => 'shopify',
                            'profile_checked'   => false,
                            'profile_supported' => true,
                        ],
                    ], JSON_THROW_ON_ERROR),
                ])
            );

            $command = new ReportAggregationTelemetryCommand(new AggregationFallbackTelemetryReporter());
            $tester = new CommandTester($command);
            $tester->execute([
                '--input'         => $this->inputFile,
                '--pilot-channel' => ['google_search_console'],
            ]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
            $this->assertStringContainsString('"missing_profile_capability_events":1', str_replace("\n", '', $tester->getDisplay()));
            $this->assertStringContainsString('"pilot_missing_profile_capability_events":1', str_replace("\n", '', $tester->getDisplay()));
        }
    }

