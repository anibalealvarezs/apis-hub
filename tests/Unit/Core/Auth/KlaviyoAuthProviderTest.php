<?php

namespace Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Anibalealvarezs\KlaviyoHubDriver\Auth\KlaviyoAuthProvider;

class KlaviyoAuthProviderTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = sys_get_temp_dir() . '/klaviyo_tokens_test.json';
        if (file_exists($this->tempTokenPath)) {
            unlink($this->tempTokenPath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempTokenPath)) {
            unlink($this->tempTokenPath);
        }
    }

    public function testSetAndGetAccessToken()
    {
        $provider = new KlaviyoAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('klaviyo-test-key');

        $this->assertEquals('klaviyo-test-key', $provider->getAccessToken());
        $this->assertTrue($provider->isValid());
    }

    public function testPersistence()
    {
        $provider = new KlaviyoAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('klaviyo-persisted-key');

        // New instance should load the same token
        $newProvider = new KlaviyoAuthProvider($this->tempTokenPath);
        $this->assertEquals('klaviyo-persisted-key', $newProvider->getAccessToken());
    }
}
