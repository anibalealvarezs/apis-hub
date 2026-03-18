<?php

namespace Tests\Integration\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Requests\CampaignRequests;
use Classes\Requests\AdGroupRequests;
use Classes\Requests\AdRequests;
use Classes\Requests\CreativeRequests;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Enums\Channel;
use Enums\Account as AccountEnum;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Tests\Integration\BaseIntegrationTestCase;

class FacebookEntityFilteringTest extends BaseIntegrationTestCase
{
    /** @var FacebookGraphApi|\PHPUnit\Framework\MockObject\MockObject */
    private $api;
    private $adAccountId = 'act_338111992380162';

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(FacebookGraphApi::class);
        
        // Setup database entities
        $account = new Account();
        $account->addName('Test Main Account');
        $this->entityManager->persist($account);

        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($this->adAccountId);
        $channeledAccount->addName('Test Ad Account');
        $channeledAccount->addChannel(Channel::facebook_marketing->value);
        $channeledAccount->addType(AccountEnum::META_AD_ACCOUNT);
        $channeledAccount->addAccount($account);
        $channeledAccount->addPlatformCreatedAt(new \DateTime());
        $this->entityManager->persist($channeledAccount);

        $this->entityManager->flush();
    }

    private function setMockChannelsConfig(?array $config): void
    {
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('channelsConfig');
        $property->setAccessible(true);
        $property->setValue(null, $config);
    }

    protected function tearDown(): void
    {
        // Reset the static property
        $this->setMockChannelsConfig(null);
        parent::tearDown();
    }

    public function testGetListFromFacebookMarketingSendsCampaignFilteringToApi(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        // Mock configuration with entity-specific filter
        $this->setMockChannelsConfig([
            'facebook_marketing' => [
                'enabled' => true,
                'ad_accounts' => [
                    ['id' => $this->adAccountId, 'enabled' => true, 'campaigns' => true]
                ],
                'CAMPAIGN' => [
                    'cache_include' => 'CAMP-'
                ]
            ]
        ]);

        $this->api->expects($this->atLeastOnce())
            ->method('getCampaigns')
            ->with(
                $this->equalTo($this->adAccountId),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($params) {
                    // Verify that the filter is actually sent to the API
                    return isset($params['filtering']) && 
                           $params['filtering'][0]['field'] === 'name' &&
                           $params['filtering'][0]['value'] === 'CAMP-';
                })
            )
            ->willReturn(['data' => []]);

        CampaignRequests::getListFromFacebookMarketing(
            null, null, $logger, null, [$this->adAccountId], $this->api
        );
    }

    public function testGetListFromFacebookMarketingSendsAdsetFilteringToApi(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->setMockChannelsConfig([
            'facebook_marketing' => [
                'enabled' => true,
                'ad_accounts' => [
                    ['id' => $this->adAccountId, 'enabled' => true, 'adsets' => true]
                ],
                'ADSET' => [
                    'cache_include' => 'SET-'
                ]
            ]
        ]);

        $this->api->expects($this->atLeastOnce())
            ->method('getAdsets')
            ->with(
                $this->equalTo($this->adAccountId),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($params) {
                    return isset($params['filtering']) && 
                           $params['filtering'][0]['field'] === 'name' &&
                           $params['filtering'][0]['value'] === 'SET-';
                })
            )
            ->willReturn(['data' => []]);

        AdGroupRequests::getListFromFacebookMarketing(
            null, null, $logger, null, [$this->adAccountId], $this->api
        );
    }

    public function testGetListFromFacebookMarketingDoesNotSendCampaignFilterWhenOnlyAdsetFilterExists(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Only ADSET filter set
        $this->setMockChannelsConfig([
            'facebook_marketing' => [
                'enabled' => true,
                'ad_accounts' => [
                    ['id' => $this->adAccountId, 'enabled' => true, 'campaigns' => true]
                ],
                'ADSET' => [
                    'cache_include' => 'SET-'
                ]
            ]
        ]);

        $this->api->expects($this->atLeastOnce())
            ->method('getCampaigns')
            ->with(
                $this->equalTo($this->adAccountId),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($params) {
                    // Filtering should NOT contain the 'name' filter because it's only for ADSET
                    if (!isset($params['filtering'])) return true;
                    foreach ($params['filtering'] as $f) {
                        if ($f['field'] === 'name') return false;
                    }
                    return true;
                })
            )
            ->willReturn(['data' => []]);

        CampaignRequests::getListFromFacebookMarketing(
            null, null, $logger, null, [$this->adAccountId], $this->api
        );
    }

    public function testGetListFromFacebookMarketingSendsAdFilteringToApi(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->api->expects($this->atLeastOnce())
            ->method('getAds')
            ->with(
                $this->equalTo($this->adAccountId),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($params) {
                    return isset($params['filtering']);
                })
            )
            ->willReturn(['data' => []]);

        AdRequests::getListFromFacebookMarketing(
            null, null, $logger, null, [$this->adAccountId], $this->api
        );
    }
}
