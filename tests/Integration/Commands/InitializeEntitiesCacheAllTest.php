<?php

namespace Tests\Integration\Commands;

use Commands\InitializeEntitiesCommand;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Page;
use Helpers\Helpers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Integration\BaseIntegrationTestCase;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;

class InitializeEntitiesCacheAllTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear static cache in Helpers
        $reflection = new \ReflectionClass(Helpers::class);
        $props = ['channelsConfig', 'projectConfig', 'entityManager'];
        foreach ($props as $propName) {
            $prop = $reflection->getProperty($propName);
            $prop->setAccessible(true);
            if ($propName === 'entityManager') {
                $prop->setValue(null, $this->entityManager);
            } else {
                $prop->setValue(null, null);
            }
        }
    }

    public function testExecuteWithCacheAllDiscoversEntities()
    {
        // Mock configuration
        $mockChannelsConfig = [
            'google_search_console' => [
                'enabled' => true,
                'cache_all' => true,
                'sites' => []
            ],
            'facebook' => [
                'enabled' => true,
                'cache_all' => true,
                'user_id' => '123',
                'accounts_group_name' => 'Test Group',
                'pages' => [],
                'ad_accounts' => []
            ]
        ];

        // Inject mock config into Helpers
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, $mockChannelsConfig);

        // Create a mock command that overrides API methods
        $command = new class($this->entityManager) extends InitializeEntitiesCommand {
            protected function fetchGscSites(array $configRaw, \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider $authProvider): array
            {
                return [
                    'siteEntry' => [
                        ['siteUrl' => 'https://example-test1.com/'],
                        ['siteUrl' => 'https://test.com/']
                    ]
                ];
            }

            protected function fetchFbPages(array $fbConfig): array
            {
                return [
                    'data' => [
                        [
                            'id' => 'page1',
                            'name' => 'Page 1',
                            'instagram_business_account' => ['id' => 'ig1']
                        ]
                    ]
                ];
            }

            protected function fetchFbAdAccounts(array $fbConfig): array
            {
                return [
                    'data' => [
                        [
                            'id' => 'act_123',
                            'name' => 'Ad Account 123'
                        ]
                    ]
                ];
            }
        };

        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        // We need to use reflection to call execute because it's protected
        $executeMethod = new \ReflectionMethod(InitializeEntitiesCommand::class, 'execute');
        $executeMethod->setAccessible(true);
        $exitCode = $executeMethod->invoke($command, $input, $output);

        $this->assertEquals(0, $exitCode);

        // Verify GSC Sites (Pages)
        $pageRepo = $this->entityManager->getRepository(Page::class);
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://example-test1.com']), 'GSC site example-test1.com not found');
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://test.com']), 'GSC site test.com not found');

        // Verify Facebook Page
        $this->assertNotNull($pageRepo->findOneBy(['platformId' => 'page1']), 'Facebook page1 not found');

        // Verify Facebook Ad Account
        $channeledAccountRepo = $this->entityManager->getRepository(ChanneledAccount::class);
        $adAccount = $channeledAccountRepo->findOneBy(['platformId' => 'act_123', 'channel' => Channel::facebook_marketing->value]);
        $this->assertNotNull($adAccount, 'Facebook Ad Account act_123 not found');

        // Verify Instagram Account
        $igAccount = $channeledAccountRepo->findOneBy(['platformId' => 'ig1', 'channel' => Channel::facebook_organic->value]);
        $this->assertNotNull($igAccount, 'Instagram account ig1 not found');
    }

    public function testExecuteWithCacheAllAndFilters()
    {
        // Mock configuration with filters
        $mockChannelsConfig = [
            'google_search_console' => [
                'enabled' => true,
                'cache_all' => true,
                'cache_exclude' => 'example.com',
                'sites' => []
            ],
            'facebook' => [
                'enabled' => true,
                'cache_all' => true,
                'user_id' => '123',
                'accounts_group_name' => 'Test Group',
                'PAGE' => [
                    'cache_include' => 'Other Page' // Should exclude "Page 1"
                ],
                'AD_ACCOUNT' => [
                    'cache_include' => '/^Ad Account 12.*/'
                ],
                'pages' => [],
                'ad_accounts' => []
            ]
        ];

        // Inject mock config into Helpers
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, $mockChannelsConfig);

        // Create a mock command that overrides API methods
        $command = new class($this->entityManager) extends InitializeEntitiesCommand {
            protected function fetchGscSites(array $configRaw, \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider $authProvider): array
            {
                return [
                    'siteEntry' => [
                        ['siteUrl' => 'https://example.com/'], // Should be excluded
                        ['siteUrl' => 'https://test.com/']    // Should be included
                    ]
                ];
            }

            protected function fetchFbPages(array $fbConfig): array
            {
                return [
                    'data' => [
                        [
                            'id' => 'page1',
                            'name' => 'Page 1', // Should be excluded (doesn't match include pattern)
                        ]
                    ]
                ];
            }

            protected function fetchFbAdAccounts(array $fbConfig): array
            {
                return [
                    'data' => [
                        [
                            'id' => 'act_123',
                            'name' => 'Ad Account 123' // Should be included
                        ],
                        [
                            'id' => 'act_456',
                            'name' => 'Other Account'   // Should be excluded
                        ]
                    ]
                ];
            }
        };

        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $executeMethod = new \ReflectionMethod(InitializeEntitiesCommand::class, 'execute');
        $executeMethod->setAccessible(true);
        $executeMethod->invoke($command, $input, $output);

        $pageRepo = $this->entityManager->getRepository(Page::class);
        $this->assertNull($pageRepo->findOneBy(['url' => 'https://example.com']), 'Excluded GSC site found');
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://test.com']), 'Included GSC site not found');
        $this->assertNull($pageRepo->findOneBy(['platformId' => 'page1']), 'Filtered out FB page found');

        $channeledAccountRepo = $this->entityManager->getRepository(ChanneledAccount::class);
        $this->assertNotNull($channeledAccountRepo->findOneBy(['platformId' => 'act_123']), 'Included Ad Account not found');
        $this->assertNull($channeledAccountRepo->findOneBy(['platformId' => 'act_456']), 'Excluded Ad Account found');
    }
}
