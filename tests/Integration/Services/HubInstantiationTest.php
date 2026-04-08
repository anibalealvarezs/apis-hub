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
        // Clear environment variables that might interfere
        $envToClear = [
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
        // 1. Arrange: Override the KLAVIYO configuration via Environment variable
        $fakeApiKey = 'pk_test_' . $this->faker->uuid;
        $config = ['klaviyo' => ['enabled' => true, 'klaviyo_api_key' => $fakeApiKey]];
        putenv('CHANNELS_CONFIG=' . json_encode($config));

        // Reset Helpers
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // 2. Act: Get the driver via Factory (This is how the system actually uses it now)
        $driver = DriverFactory::get('klaviyo', $this->createMock(\Psr\Log\LoggerInterface::class));
        
        // 3. Assert
        $this->assertInstanceOf(\Anibalealvarezs\KlaviyoHubDriver\Drivers\KlaviyoDriver::class, $driver);
        
        // Verify the Auth Provider was correctly initialized with the API key via Reflection
        $driverReflection = new \ReflectionClass($driver);
        $authProp = $driverReflection->getProperty('authProvider');
        $authProp->setAccessible(true);
        /** @var \Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface $authProvider */
        $authProvider = $authProp->getValue($driver);
        
        $this->assertEquals($fakeApiKey, $authProvider->getAccessToken());
        
        // Clean up
        putenv('CHANNELS_CONFIG');
    }

    public function testHubSuccessfullyInstantiatesGoogleSearchConsoleClientUsingMixedConfig(): void
    {
        // 1. Arrange: Setup a mixed configuration
        $globalRefToken = 'global_refresh_token_' . $this->faker->uuid;
        $specificClientId = 'specific_client_id_' . $this->faker->uuid;
        $scopes = 'scope1, scope2, scope3';
        
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
                'scope' => $scopes,
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

        // 2. Act: Use Reflection to call the private initialization method in MetricRequests
        $metricRequestsReflection = new \ReflectionClass(\Classes\Requests\MetricRequests::class);
        $method = $metricRequestsReflection->getMethod('initializeSearchConsoleApi');
        $method->setAccessible(true);
        
        // validateGoogleConfig also needs to be bypassed or executed
        $loadedConfig = \Helpers\GoogleSearchConsoleHelpers::validateGoogleConfig($this->createMock(\Psr\Log\LoggerInterface::class));
        
        /** @var \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi $apiInstance */
        $apiInstance = $method->invoke(null, $loadedConfig, $this->createMock(\Psr\Log\LoggerInterface::class));

        // 3. Assert
        $this->assertInstanceOf(\Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi::class, $apiInstance);
        
        $apiReflection = new \ReflectionClass(\Anibalealvarezs\GoogleApi\Google\GoogleApi::class);
        
        $clientIdProp = $apiReflection->getProperty('clientId');
        $clientIdProp->setAccessible(true);
        // Should have picked up the specific client ID
        $this->assertEquals($specificClientId, $clientIdProp->getValue($apiInstance));

        $refreshTokenProp = $apiReflection->getProperty('refreshToken');
        $refreshTokenProp->setAccessible(true);
        // Should have picked up the global refresh token as fallback
        $this->assertEquals($globalRefToken, $refreshTokenProp->getValue($apiInstance));

        $scopesProp = $apiReflection->getProperty('scopes');
        $scopesProp->setAccessible(true);
        // Should have parsed the comma-separated string into an array
        $this->assertEquals(['scope1', 'scope2', 'scope3'], $scopesProp->getValue($apiInstance));

        $tokenPathProp = $apiReflection->getProperty('tokenPath');
        $tokenPathProp->setAccessible(true);
        $this->assertEquals('/tmp/google_tokens.json', $tokenPathProp->getValue($apiInstance));

        // Clean up
        putenv('CHANNELS_CONFIG');
    }
}
