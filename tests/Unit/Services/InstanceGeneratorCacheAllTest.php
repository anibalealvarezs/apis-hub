<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Services\InstanceGeneratorService;

class InstanceGeneratorCacheAllTest extends TestCase
{
    private array $originalChannelsConfig;
    private array $originalProjectConfig;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Backup original configs
        $reflection = new ReflectionClass(Helpers::class);
        
        $channelsProp = $reflection->getProperty('channelsConfig');
        $channelsProp->setAccessible(true);
        $this->originalChannelsConfig = (array) $channelsProp->getValue();

        $projectProp = $reflection->getProperty('projectConfig');
        $projectProp->setAccessible(true);
        $this->originalProjectConfig = (array) $projectProp->getValue();
    }

    protected function tearDown(): void
    {
        // Restore original configs
        $reflection = new ReflectionClass(Helpers::class);
        
        $channelsProp = $reflection->getProperty('channelsConfig');
        $channelsProp->setAccessible(true);
        $channelsProp->setValue(null, $this->originalChannelsConfig);

        $projectProp = $reflection->getProperty('projectConfig');
        $projectProp->setAccessible(true);
        $projectProp->setValue(null, $this->originalProjectConfig);
        
        parent::tearDown();
    }

    private function setMockChannelsConfig(array $config): void
    {
        $reflection = new ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    private function setMockProjectConfig(array $config): void
    {
        $reflection = new ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('projectConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    public function testHasActiveEntitiesWithCacheAll(): void
    {
        $mockChannelsConfig = [
            'facebook' => [
                'enabled' => true,
                'cache_all' => true,
                'ad_accounts' => [] // Empty but cache_all is true
            ],
            'google_search_console' => [
                'enabled' => true,
                'cache_all' => true,
                'sites' => [] // Empty but cache_all is true
            ]
        ];

        $this->setMockChannelsConfig($mockChannelsConfig);

        $service = new InstanceGeneratorService();
        $reflection = new ReflectionClass(InstanceGeneratorService::class);
        $method = $reflection->getMethod('hasActiveEntities');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'facebook_marketing'), 'Facebook Marketing should be active with cache_all');
        $this->assertTrue($method->invoke($service, 'facebook_organic'), 'Facebook Organic should be active with cache_all');
        $this->assertTrue($method->invoke($service, 'gsc'), 'GSC should be active with cache_all');
    }

    public function testHasActiveEntitiesWithoutCacheAllAndEmptyList(): void
    {
        $mockChannelsConfig = [
            'facebook' => [
                'enabled' => true,
                'cache_all' => false,
                'ad_accounts' => []
            ]
        ];

        $this->setMockChannelsConfig($mockChannelsConfig);

        $service = new InstanceGeneratorService();
        $reflection = new ReflectionClass(InstanceGeneratorService::class);
        $method = $reflection->getMethod('hasActiveEntities');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'facebook_marketing'), 'Facebook Marketing should be inactive without cache_all and empty list');
    }

    public function testGenerateIncludesCacheAllChannels(): void
    {
        $mockProjectConfig = [
            'rules' => [
                'facebook_marketing' => [
                    'enabled' => true,
                    'entities_sync' => 'ad_account',
                    'history_months' => 3,
                    'recent_cron_minute' => 0,
                    'recent_cron_hour' => 1
                ]
            ]
        ];
        $this->setMockProjectConfig($mockProjectConfig);

        $mockChannelsConfig = [
            'facebook' => [
                'enabled' => true,
                'cache_all' => true,
                'ad_accounts' => []
            ]
        ];
        $this->setMockChannelsConfig($mockChannelsConfig);

        $service = new InstanceGeneratorService();
        $instances = $service->generate(false, 8080);

        $names = array_column($instances, 'name');
        $this->assertContains('facebook-marketing-entities-sync', $names);
        $this->assertContains('facebook-marketing-recent', $names);
    }
}
