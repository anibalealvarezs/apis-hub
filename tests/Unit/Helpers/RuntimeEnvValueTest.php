<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Helpers\Helpers;
use PHPUnit\Framework\TestCase;

/**
 * Verifies runtime .env resolution used to hot-reload credentials into
 * long-running (Swoole) processes without a container restart.
 */
class RuntimeEnvValueTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = realpath(__DIR__ . '/../../../') . DIRECTORY_SEPARATOR . '.env.runtime_env_test';
        file_put_contents($this->envPath, "TYPESAFE_API_KEY=apikey_runtime_initial\nTYPESAFE_BASE_URL=https://api.typesafe.ai/v1/\n");

        putenv('ENV_FILE=.env.runtime_env_test');
        putenv('TYPESAFE_API_KEY_LEGACY=legacy_value');

        Helpers::resetEnvCache();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->envPath)) {
            unlink($this->envPath);
        }

        putenv('ENV_FILE');
        putenv('TYPESAFE_API_KEY_LEGACY');
        Helpers::resetConfigs();
    }

    public function testReadsValueFromEnvFile(): void
    {
        $this->assertSame('apikey_runtime_initial', Helpers::getEnvValue('TYPESAFE_API_KEY'));
    }

    public function testPicksUpUpdatedEnvFileValueWithoutRestart(): void
    {
        $this->assertSame('apikey_runtime_initial', Helpers::getEnvValue('TYPESAFE_API_KEY'));

        file_put_contents($this->envPath, "TYPESAFE_API_KEY=apikey_runtime_updated\n");
        Helpers::resetEnvCache();

        $this->assertSame('apikey_runtime_updated', Helpers::getEnvValue('TYPESAFE_API_KEY'));
    }

    public function testEmptyEnvValueIsReturnedAsEmptyString(): void
    {
        file_put_contents($this->envPath, "TYPESAFE_API_KEY=\n");
        Helpers::resetEnvCache();

        $this->assertSame('', Helpers::getEnvValue('TYPESAFE_API_KEY'));
    }

    public function testFallsBackToProcessEnvWhenKeyNotDefinedInEnvFile(): void
    {
        $this->assertSame('legacy_value', Helpers::getEnvValue('TYPESAFE_API_KEY_LEGACY'));
    }

    public function testReturnsDefaultWhenKeyIsNotResolvable(): void
    {
        $this->assertNull(Helpers::getEnvValue('TYPESAFE_UNKNOWN_KEY'));
        $this->assertSame('fallback', Helpers::getEnvValue('TYPESAFE_UNKNOWN_KEY', 'fallback'));
    }
}