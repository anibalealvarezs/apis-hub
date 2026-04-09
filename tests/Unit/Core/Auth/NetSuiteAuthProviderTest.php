<?php

namespace Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Anibalealvarezs\NetSuiteHubDriver\Auth\NetSuiteAuthProvider;

class NetSuiteAuthProviderTest extends TestCase
{
    private string $tempTokenPath;

    protected function setUp(): void
    {
        $this->tempTokenPath = sys_get_temp_dir() . '/netsuite_tokens_test.json';
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

    public function testSetAndGetCredentials()
    {
        $provider = new NetSuiteAuthProvider($this->tempTokenPath);
        $creds = [
            'consumer_id' => 'c_id',
            'consumer_secret' => 'c_sec',
            'token_id' => 't_id',
            'token_secret' => 't_sec',
            'account_id' => 'a_id'
        ];
        $provider->setCredentials($creds);

        $result = $provider->getCredentials();
        $this->assertEquals('c_id', $result['consumer_id']);
        $this->assertEquals('t_id', $provider->getAccessToken());
        $this->assertTrue($provider->isValid());
    }

    public function testPersistence()
    {
        $provider = new NetSuiteAuthProvider($this->tempTokenPath);
        $provider->setCredentials(['consumer_id' => 'persistent_id', 'token_id' => 'persistent_token']);

        $newProvider = new NetSuiteAuthProvider($this->tempTokenPath);
        $this->assertEquals('persistent_id', $newProvider->getCredentials()['consumer_id']);
        $this->assertEquals('persistent_token', $newProvider->getAccessToken());
    }
}
