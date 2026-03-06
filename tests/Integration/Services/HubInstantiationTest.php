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
}
