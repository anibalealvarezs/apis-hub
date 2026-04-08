<?php

namespace Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Core\Auth\ShopifyAuthProvider;

class ShopifyAuthProviderTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = sys_get_temp_dir() . '/shopify_tokens_test.json';
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
        $provider = new ShopifyAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('test-token', 'test-shop', '2024-04');

        $this->assertEquals('test-token', $provider->getAccessToken());
        $this->assertEquals('test-shop', $provider->getShopName());
        $this->assertEquals('2024-04', $provider->getVersion());
        $this->assertTrue($provider->isValid());
    }

    public function testPersistence()
    {
        $provider = new ShopifyAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('persisted-token', 'persisted-shop');

        // New instance should load the same token
        $newProvider = new ShopifyAuthProvider($this->tempTokenPath);
        $this->assertEquals('persisted-token', $newProvider->getAccessToken());
        $this->assertEquals('persisted-shop', $newProvider->getShopName());
    }
}
