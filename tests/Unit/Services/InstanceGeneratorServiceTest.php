<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Services\InstanceGeneratorService;
use DateTimeImmutable;

class InstanceGeneratorServiceTest extends TestCase
{
    private string $configPath;
    private string $rulesPath;
    private string|bool $originalAppEnv;

    private function setMockChannelsConfig(array $config): void
    {
        $reflection = new \ReflectionClass(\Helpers\Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    private function setMockProjectConfig(array $config): void
    {
        $reflection = new \ReflectionClass(\Helpers\Helpers::class);
        $property = $reflection->getProperty('projectConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure we're not in demo mode for tests
        $this->originalAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        \Helpers\Helpers::resetConfigs();

        $this->setMockProjectConfig([
            'rules' => [
                'facebook_marketing' => [
                    'enabled' => true,
                    'entities_sync' => 'ad_account',
                    'history_months' => 3,
                    'recent_cron_minute' => 0,
                    'recent_cron_hour' => 1
                ],
                'facebook_organic' => [
                    'enabled' => true,
                    'entities_sync' => 'page',
                    'history_months' => 3,
                    'recent_cron_minute' => 0,
                    'recent_cron_hour' => 1
                ],
                'gsc' => [
                    'enabled' => true,
                    'entities_sync' => null,
                    'history_months' => 3,
                    'recent_cron_minute' => 0,
                    'recent_cron_hour' => 1
                ]
            ]
        ]);

        $this->setMockChannelsConfig([
            'facebook_marketing' => [
                'enabled' => true,
                'cache_all' => true,
                'ad_accounts' => []
            ],
            'facebook_organic' => [
                'enabled' => true,
                'cache_all' => true,
                'pages' => []
            ],
            'google_search_console' => [
                'enabled' => true,
                'cache_all' => true,
                'sites' => []
            ]
        ]);
    }

    protected function tearDown(): void
    {
        // Restore environment
        putenv("APP_ENV=" . ($this->originalAppEnv ?: ''));
        \Helpers\Helpers::resetConfigs();
        parent::tearDown();
    }

    public function testGenerateReturnsCorrectInstances(): void
    {
        $service = new InstanceGeneratorService();
        $instances = $service->generate(true, 8080);

        $this->assertIsArray($instances);
        $this->assertNotEmpty($instances);

        foreach ($instances as $instance) {
            $this->assertArrayHasKey('name', $instance);
            $this->assertArrayHasKey('port', $instance);
            $this->assertArrayHasKey('channel', $instance);
            $this->assertArrayHasKey('entity', $instance);
        }

        // Check for specific expected names (recent)
        $names = array_column($instances, 'name');
        $this->assertContains('facebook-marketing-recent', $names);
        $this->assertContains('facebook-organic-recent', $names);
        $this->assertContains('gsc-recent', $names);
    }

    public function testDependencyChain(): void
    {
        $service = new InstanceGeneratorService();
        $instances = $service->generate(true, 8080);

        // Filter by channel
        $fbMarketing = array_values(array_filter($instances, fn($i) => $i['channel'] === 'facebook_marketing'));
        
        $this->assertGreaterThan(0, count($fbMarketing));

        for ($i = 1; $i < count($fbMarketing); $i++) {
            $this->assertArrayHasKey('requires', $fbMarketing[$i]);
            $this->assertEquals($fbMarketing[$i-1]['name'], $fbMarketing[$i]['requires']);
        }
    }
}
