<?php

namespace Tests\Integration\Services;

use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Helpers\Helpers;
use Tests\Integration\BaseIntegrationTestCase;

class HubInstantiationTest extends BaseIntegrationTestCase
{
    public function testHubSuccessfullyInstantiatesKlaviyoClientUsingConfig(): void
    {
        // 1. Arrange: Override the KLAVIYO configuration via Environment variable
        // This validates our integration with dynamic configurations.
        $fakeApiKey = 'pk_test_' . $this->faker->uuid;
        $config = ['klaviyo' => ['enabled' => true, 'klaviyo_api_key' => $fakeApiKey]];
        putenv('CHANNELS_CONFIG=' . json_encode($config));

        // Let Helpers re-parse the environment variable
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // 2. Act: Fetch the config as the apis-hub system would natively do
        $loadedConfig = Helpers::getChannelsConfig()['klaviyo'];
        
        // Let's spawn the actual API client that the requests use
        $klaviyoClient = new KlaviyoApi(
            apiKey: $loadedConfig['klaviyo_api_key']
        );

        // 3. Assert: The hub successfully stitched its configuration into the external payload
        $this->assertEquals($fakeApiKey, $loadedConfig['klaviyo_api_key']);
        
        $clientReflection = new \ReflectionClass(\Anibalealvarezs\ApiSkeleton\Clients\ApiKeyClient::class);
        $tokenProperty = $clientReflection->getProperty('apiKey');
        $tokenProperty->setAccessible(true);
        
        // Asserting the instantiated underlying package correctly ingested the hub's token!
        $this->assertEquals($fakeApiKey, $tokenProperty->getValue($klaviyoClient));
        
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
                'user_id' => 'global@example.com'
            ],
            'google_search_console' => [
                'enabled' => true,
                'client_id' => $specificClientId,
                'scope' => $scopes,
                'token_path' => '/tmp/google_tokens.json'
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
        
        /** @var \Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi $apiInstance */
        $apiInstance = $method->invoke(null, $loadedConfig, $this->createMock(\Psr\Log\LoggerInterface::class));

        // 3. Assert
        $this->assertInstanceOf(\Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi::class, $apiInstance);
        
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
