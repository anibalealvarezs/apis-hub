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
        // 1. Prepare environment
        $this->entityManager->getConnection()->executeStatement("DELETE FROM pages");
        $this->entityManager->getConnection()->executeStatement("DELETE FROM channeled_accounts");

        // 2. Act
        $command = new class($this->entityManager) extends InitializeEntitiesCommand {
            protected function initializeChannelEntities(): void
            {
                // Simulate GSC Driver initialization
                $gscInitializer = new \Anibalealvarezs\GoogleHubDriver\Services\GscInitializerService($this->getEntityManager());
                $gscInitializer->initialize('google_search_console', ['enabled' => true, 'cache_all' => true], [
                    ['url' => 'https://example-test1.com/'],
                    ['url' => 'https://test.com/']
                ]);

                // Simulate FB Drivers initialization
                $fbInitializer = new \Anibalealvarezs\MetaHubDriver\Services\MetaInitializerService($this->getEntityManager());
                $fbInitializer->initialize('facebook_marketing', ['enabled' => true, 'cache_all' => true], [
                    'ad_accounts' => [['id' => 'act_123', 'name' => 'Ad Account 123']]
                ]);
                $fbInitializer->initialize('facebook_organic', ['enabled' => true, 'cache_all' => true], [
                    'pages' => [[
                        'id' => 'page1',
                        'name' => 'Page 1',
                        'instagram_business_account' => ['id' => 'ig1']
                    ]]
                ]);
            }

            public function getEntityManager() { return $this->entityManager; }
        };

        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $executeMethod = new \ReflectionMethod(InitializeEntitiesCommand::class, 'execute');
        $executeMethod->setAccessible(true);
        $exitCode = $executeMethod->invoke($command, $input, $output);

        $this->assertEquals(0, $exitCode);

        // 3. Verify
        $pageRepo = $this->entityManager->getRepository(Page::class);
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://example-test1.com']), 'GSC site example-test1.com not found');
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://test.com']), 'GSC site test.com not found');
        $this->assertNotNull($pageRepo->findOneBy(['platformId' => 'page1']), 'Facebook page1 not found');

        $channeledAccountRepo = $this->entityManager->getRepository(ChanneledAccount::class);
        $adAccount = $channeledAccountRepo->findOneBy(['platformId' => 'act_123', 'channel' => Channel::facebook_marketing->value]);
        $this->assertNotNull($adAccount, 'Facebook Ad Account act_123 not found');

        $igAccount = $channeledAccountRepo->findOneBy(['platformId' => 'ig1', 'channel' => Channel::facebook_organic->value]);
        $this->assertNotNull($igAccount, 'Instagram account ig1 not found');
    }

    public function testExecuteWithCacheAllAndFilters()
    {
        // 1. Prepare environment
        $this->entityManager->getConnection()->executeStatement("DELETE FROM pages");
        $this->entityManager->getConnection()->executeStatement("DELETE FROM channeled_accounts");

        // 2. Act
        $command = new class($this->entityManager) extends InitializeEntitiesCommand {
            protected function initializeChannelEntities(): void
            {
                // Simulate GSC Driver initialization with filters
                $gscInitializer = new \Anibalealvarezs\GoogleHubDriver\Services\GscInitializerService($this->getEntityManager());
                $gscInitializer->initialize('google_search_console', [
                    'enabled' => true, 
                    'cache_all' => true,
                    'cache_exclude' => 'example.com'
                ], [
                    ['url' => 'https://example.com/'],
                    ['url' => 'https://test.com/']
                ]);

                // Simulate FB Drivers initialization with filters
                $fbInitializer = new \Anibalealvarezs\MetaHubDriver\Services\MetaInitializerService($this->getEntityManager());
                $fbInitializer->initialize('facebook_marketing', [
                    'enabled' => true, 
                    'cache_all' => true,
                    'AD_ACCOUNT' => ['cache_include' => '/^Ad Account 12.*/']
                ], [
                    'ad_accounts' => [
                        ['id' => 'act_123', 'name' => 'Ad Account 123'],
                        ['id' => 'act_456', 'name' => 'Other Account']
                    ]
                ]);
                $fbInitializer->initialize('facebook_organic', [
                    'enabled' => true, 
                    'cache_all' => true,
                    'PAGE' => ['cache_include' => 'Other Page']
                ], [
                    'pages' => [[
                        'id' => 'page1',
                        'name' => 'Page 1'
                    ]]
                ]);
            }

            public function getEntityManager() { return $this->entityManager; }
        };

        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $executeMethod = new \ReflectionMethod(InitializeEntitiesCommand::class, 'execute');
        $executeMethod->setAccessible(true);
        $executeMethod->invoke($command, $input, $output);

        // 3. Verify
        $pageRepo = $this->entityManager->getRepository(Page::class);
        $this->assertNull($pageRepo->findOneBy(['url' => 'https://example.com']), 'Excluded GSC site found');
        $this->assertNotNull($pageRepo->findOneBy(['url' => 'https://test.com']), 'Included GSC site not found');
        $this->assertNull($pageRepo->findOneBy(['platformId' => 'page1']), 'Filtered out FB page found');

        $channeledAccountRepo = $this->entityManager->getRepository(ChanneledAccount::class);
        $this->assertNotNull($channeledAccountRepo->findOneBy(['platformId' => 'act_123']), 'Included Ad Account not found');
        $this->assertNull($channeledAccountRepo->findOneBy(['platformId' => 'act_456']), 'Excluded Ad Account found');
    }
}
