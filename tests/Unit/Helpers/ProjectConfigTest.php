<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Exceptions\ConfigurationException;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ProjectConfigTest extends TestCase
{
    private string $configDir;
    private array $backupFiles = [];
    private array $mandatoryFiles = ['database.yaml', 'security.yaml', 'app.yaml'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir = __DIR__ . '/../../../config';
        
        // Reset static property
        $reflection = new ReflectionClass(Helpers::class);
        $prop = $reflection->getProperty('projectConfig');
        $prop->setValue(null, null);
    }

    /**
     * @throws ConfigurationException
     */
    public function testGetProjectConfigThrowsExceptionWhenMandatoryFilesAreMissing(): void
    {
        // Temporarily rename mandatory files to simulate missing
        foreach ($this->mandatoryFiles as $file) {
            $path = $this->configDir . '/' . $file;
            if (file_exists($path)) {
                $backup = $path . '.testbak';
                rename($path, $backup);
                $this->backupFiles[$path] = $backup;
            }
        }

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage('Critical configuration files are missing');
            Helpers::getProjectConfig();
        } finally {
            // Restore files
            foreach ($this->backupFiles as $original => $backup) {
                if (file_exists($backup)) {
                    rename($backup, $original);
                }
            }
        }
    }

    public function testGetProjectConfigSucceedsWhenFilesExist(): void
    {
        // This assumes the environment has these files (which it should for developer machines)
        $config = Helpers::getProjectConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('database', $config);
        $this->assertArrayHasKey('security', $config);
        $this->assertArrayHasKey('project', $config);
    }
}
