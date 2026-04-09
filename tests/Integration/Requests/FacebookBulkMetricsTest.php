<?php

namespace Tests\Integration\Requests;

use Classes\Requests\MetricRequests;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledMetric;
use Enums\Channel;
use Enums\Account as AccountEnum;
use Psr\Log\LoggerInterface;
use Tests\Integration\BaseIntegrationTestCase;
use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver;
use Anibalealvarezs\MetaHubDriver\Auth\FacebookAuthProvider;

class FacebookBulkMetricsTest extends BaseIntegrationTestCase
{
    /** @var FacebookGraphApi|\PHPUnit\Framework\MockObject\MockObject */
    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(FacebookGraphApi::class);
    }

    public function testDriverSyncStoresMetrics(): void
    {
        // 1. Arrange
        $adAccountId = 'act_12345';
        
        $account = new Account();
        $account->addName('Default');
        $this->entityManager->persist($account);

        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($adAccountId)
            ->addName('Test Account')
            ->addChannel(Channel::facebook_marketing->value)
            ->addType(AccountEnum::META_AD_ACCOUNT)
            ->addAccount($account)
            ->addPlatformCreatedAt(new \DateTime());
        $this->entityManager->persist($channeledAccount);
        $this->entityManager->flush();

        $today = date('Y-m-d');
        
        // Setup API mock return
        $this->api->method('getAdAccountInsights')
            ->willReturn([
                'data' => [
                    [
                        'account_id' => $adAccountId,
                        'impressions' => '1000',
                        'clicks' => '50',
                        'spend' => '10.5',
                        'date_start' => $today,
                        'date_stop' => $today,
                    ]
                ]
            ]);

        // Mock Auth Provider
        $auth = $this->createMock(FacebookAuthProvider::class);
        $auth->method('getAccessToken')->willReturn('fake_token');

        // Create Driver Mock to inject our mocked API
        $driverMock = $this->getMockBuilder(FacebookMarketingDriver::class)
            ->setConstructorArgs([$auth, $this->createMock(LoggerInterface::class)])
            ->onlyMethods(['initializeApi'])
            ->getMock();
        
        $driverMock->method('initializeApi')->willReturn($this->api);
        $driverMock->setDataProcessor([MetricRequests::class, 'persist']);

        // 2. Act
        $startDate = new \DateTime($today);
        $endDate = new \DateTime($today);
        $config = [
            'ad_accounts' => [['id' => $adAccountId]],
            'accounts_group_name' => 'Default'
        ];
        
        $response = $driverMock->sync($startDate, $endDate, $config);

        // 3. Assert
        $this->assertEquals(200, $response->getStatusCode());
        
        $metrics = $this->entityManager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertNotEmpty($metrics);
        
        $found = false;
        foreach ($metrics as $metric) {
            if ($metric->getMetric()->getMetricConfig()->getName() === 'impressions') {
                $this->assertEquals(1000, $metric->getMetric()->getValue());
                $found = true;
            }
        }
        $this->assertTrue($found, 'Impressions metric not persisted');
    }
}
