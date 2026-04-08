<?php

namespace Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Core\Auth\GoogleAuthProvider;

class GoogleAuthProviderTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = sys_get_temp_dir() . '/google_tokens_test.json';
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

    public function test_it_loads_credentials_from_json()
    {
        $data = [
            'google_auth' => [
                'access_token' => 'test_token',
                'refresh_token' => 'test_refresh',
                'scopes' => ['scope1'],
                'expires_at' => date('Y-m-d H:i:s', time() + 3600)
            ]
        ];
        file_put_contents($this->tempTokenPath, json_encode($data));

        $provider = new GoogleAuthProvider($this->tempTokenPath);
        
        $this->assertEquals('test_token', $provider->getAccessToken());
        $this->assertEquals(['scope1'], $provider->getScopes());
    }

    public function test_it_updates_credentials_correctly()
    {
        $initialData = [
            'google_auth' => [
                'access_token' => 'old_token',
                'refresh_token' => 'refresh'
            ]
        ];
        file_put_contents($this->tempTokenPath, json_encode($initialData));

        $provider = new GoogleAuthProvider($this->tempTokenPath);
        $provider->setAccessToken('new_token');

        // Force save by calling getAccessToken (not ideal, normally it saves on destruct or explicit call)
        // In our case it saves on __destruct, so we let it go out of scope
        unset($provider);

        $savedData = json_decode(file_get_contents($this->tempTokenPath), true);
        $this->assertEquals('new_token', $savedData['google_auth']['access_token']);
        $this->assertEquals('refresh', $savedData['google_auth']['refresh_token']);
    }
}
