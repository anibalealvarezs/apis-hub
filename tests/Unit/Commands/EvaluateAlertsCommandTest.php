<?php

namespace Tests\Unit\Commands;

use Commands\Analytics\EvaluateAlertsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class EvaluateAlertsCommandTest extends TestCase
{
    private string $tmpConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpConfigPath = sys_get_temp_dir() . '/test_alerts_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpConfigPath)) {
            unlink($this->tmpConfigPath);
        }
        parent::tearDown();
    }

    public function test_command_handles_missing_config_file_gracefully()
    {
        $command = new EvaluateAlertsCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => '/non/existent/alerts_config.json',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Alerts config file not found', $tester->getDisplay());
    }

    public function test_command_handles_empty_alerts_config()
    {
        file_put_contents($this->tmpConfigPath, json_encode([]));

        $command = new EvaluateAlertsCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => $this->tmpConfigPath,
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No active alerts defined', $tester->getDisplay());
    }

    public function test_command_evaluates_alert_dry_run()
    {
        $alerts = [
            [
                'id' => 1,
                'name' => 'Test High Spend Alert',
                'source_type' => 'metric',
                'ast' => [
                    'type' => 'value',
                    'value' => 1500,
                ],
                'filters' => ['startDate' => '2026-08-01', 'endDate' => '2026-08-01'],
                'aggregation_method' => 'latest',
                'upper_limit' => 1000.00,
                'lower_limit' => null,
                'schedule_type' => 'daily',
                'next_evaluation_at' => date('c', time() - 3600), // Due 1 hour ago
                'calculation_lines' => [
                    [
                        'id' => 10,
                        'label' => 'Main Account',
                        'asset_filter' => ['asset_platform_id' => 'act_123'],
                    ],
                ],
            ],
        ];

        file_put_contents($this->tmpConfigPath, json_encode($alerts));

        $command = new EvaluateAlertsCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => $this->tmpConfigPath,
            '--dry-run' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Evaluating Alert:', $display);
        $this->assertStringContainsString('Test High Spend Alert', $display);
        $this->assertStringContainsString('Evaluated Value: 1500', $display);
        $this->assertStringContainsString('TRIGGERED: Upper limit (1000) exceeded!', $display);
        $this->assertStringContainsString('[Dry Run] Skipping HTTP callback', $display);
    }
}
