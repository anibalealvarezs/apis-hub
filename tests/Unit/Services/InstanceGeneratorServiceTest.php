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

    protected function setUp(): void
    {
        parent::setUp();
        \Helpers\Helpers::resetConfigs();
        // We use the real Helpers::getProjectConfig() so we need to mock the environment or files if possible
        // But since we want to be safe, we'll try to use a mockable approach or just ensure the test config exists
        $this->configPath = __DIR__ . '/../../../config/app.yaml';
        $this->rulesPath = __DIR__ . '/../../../config/instances_rules.yaml';
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
        
        // At least sync + 8 quarters + recent = 10? depends on today's date
        $this->assertGreaterThan(5, count($fbMarketing));

        for ($i = 1; $i < count($fbMarketing); $i++) {
            $this->assertArrayHasKey('requires', $fbMarketing[$i]);
            $this->assertEquals($fbMarketing[$i-1]['name'], $fbMarketing[$i]['requires']);
        }
    }
}
