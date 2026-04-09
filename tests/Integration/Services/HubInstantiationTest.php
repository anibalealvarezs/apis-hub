<?php

use Core\Drivers\DriverFactory;
use Helpers\Helpers;
use Tests\Integration\BaseIntegrationTestCase;

class HubInstantiationTest extends BaseIntegrationTestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Clear environment variables
        $envToClear = [
            'CHANNELS_CONFIG',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GOOGLE_REFRESH_TOKEN',
            'GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN',
            'GOOGLE_REDIRECT_URI',
            'GOOGLE_USER_ID',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_ID',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET',
            'GOOGLE_SEARCH_CONSOLE_TOKEN'
        ];
        foreach ($envToClear as $envKey) {
            $this->originalEnv[$envKey] = getenv($envKey);
            putenv($envKey);
        }
    }

    protected function tearDown(): void
    {
        // Restore environment variables
        foreach ($this->originalEnv as $envKey => $val) {
            if ($val !== false) {
                putenv($envKey . '=' . $val);
            } else {
                putenv($envKey);
            }
        }
        parent::tearDown();
    }

    public function testHubSuccessfullyInstantiatesKlaviyoDriverUsingConfig(): void
    {
        // 1. Arrange
        $fakeApiKey = 'pk_test_' . $this->faker->uuid;
        $config = ['klaviyo' => ['enabled' => true, 'klaviyo_api_key' => $fakeApiKey]];
        putenv('CHANNELS_CONFIG=' . json_encode($config));

        // Reset Helpers
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // 2. Act
        $driver = DriverFactory::get('klaviyo', $this->createMock(\Psr\Log\LoggerInterface::class));
        
        // 3. Assert
        $this->assertInstanceOf(\Anibalealvarezs\KlaviyoHubDriver\Drivers\KlaviyoDriver::class, $driver);
        
        $driverReflection = new \ReflectionClass($driver);
        $authProp = $driverReflection->getProperty('authProvider');
        $authProp->setAccessible(true);
        /** @var \Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface $authProvider */
        $authProvider = $authProp->getValue($driver);
        
        $this->assertEquals($fakeApiKey, $authProvider->getAccessToken());
    }

    public function testHubSuccessfullyInstantiatesGoogleSearchConsoleDriverUsingMixedConfig(): void
    {
        // 1. Arrange
        $globalRefToken = 'global_refresh_token_' . $this->faker->uuid;
        $specificClientId = 'specific_client_id_' . $this->faker->uuid;
        
        $config = [
            'google' => [
                'client_id' => 'global_client_id',
                'client_secret' => 'global_secret',
                'refresh_token' => $globalRefToken,
                'user_id' => 'global@example.com',
                'redirect_uri' => 'http://localhost'
            ],
            'google_search_console' => [
                'enabled' => true,
                'client_id' => $specificClientId,
                'scope' => 'scope1, scope2',
                'token_path' => '/tmp/google_tokens.json',
                'sites' => []
            ]
        ];
        putenv('CHANNELS_CONFIG=' . json_encode($config));

        // Reset Helpers
        $reflection = new \ReflectionClass(\Helpers\Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // 2. Act
        $driver = DriverFactory::get('google_search_console', $this->createMock(\Psr\Log\LoggerInterface::class));

        // 3. Assert
        $this->assertInstanceOf(\Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver::class, $driver);
        
        $driverReflection = new \ReflectionClass($driver);
        $authProp = $driverReflection->getProperty('authProvider');
        $authProp->setAccessible(true);
        /** @var \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider $authProvider */
        $authProvider = $authProp->getValue($driver);
        
        // Verify cross-platform fallback logic in GoogleAuthProvider
        $authReflection = new \ReflectionClass($authProvider);
        $configProp = $authReflection->getProperty('config');
        $configProp->setAccessible(true);
        $resolvedConfig = $configProp->getValue($authProvider);

        $this->assertEquals($specificClientId, $resolvedConfig['client_id']);
        $this->assertEquals($globalRefToken, $resolvedConfig['refresh_token'] ?? $resolvedConfig['token'] ?? '');
    }
}
