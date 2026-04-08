<?php

namespace Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Core\Auth\FacebookAuthProvider;

class FacebookAuthProviderTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = __DIR__ . '/facebook_tokens_test.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempTokenPath)) {
            unlink($this->tempTokenPath);
        }
    }

    public function test_it_loads_credentials_from_json()
    {
        $data = [
            'facebook_auth' => [
                'access_token' => 'fb_test_token',
                'user_id' => '12345',
                'expires_at' => date('Y-m-d H:i:s', time() + 7200)
            ]
        ];
        file_put_contents($this->tempTokenPath, json_encode($data));

        $provider = new FacebookAuthProvider($this->tempTokenPath);
        
        $this->assertEquals('fb_test_token', $provider->getAccessToken());
        $this->assertEquals('12345', $provider->getUserId());
        $this->assertFalse($provider->isExpired());
    }

    public function test_it_falls_back_to_marketing_key()
    {
        $data = [
            'facebook_marketing' => [
                'access_token' => 'legacy_token',
                'user_id' => '67890'
            ]
        ];
        file_put_contents($this->tempTokenPath, json_encode($data));

        $provider = new FacebookAuthProvider($this->tempTokenPath);
        
        $this->assertEquals('legacy_token', $provider->getAccessToken());
        $this->assertEquals('67890', $provider->getUserId());
    }

    public function test_it_updates_credentials_correctly()
    {
        $provider = new FacebookAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('new_fb_token');

        $this->assertEquals('new_fb_token', $provider->getAccessToken());
        
        $savedData = json_decode(file_get_contents($this->tempTokenPath), true);
        $this->assertEquals('new_fb_token', $savedData['facebook_auth']['access_token']);
    }
}
