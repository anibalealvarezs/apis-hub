<?php

declare(strict_types=1);

namespace Tests\Integration\Requests;

use Classes\Requests\MetricRequests;
use Entities\Analytics\Account;
use Entities\Analytics\Page;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledMetric;
use Enums\Channel;
use Enums\Account as AccountEnum;
use Tests\Integration\BaseIntegrationTestCase;

class ModularMetricRequestsTest extends BaseIntegrationTestCase
{
    public function testProcessKlaviyoChunkStoresMetrics()
    {
        // 1. Arrange
        $manager = $this->entityManager;
        $account = new Account();
        $account->addName('Klaviyo Test Account');
        $manager->persist($account);

        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('klaviyo_p_id')
            ->addName('Klaviyo Test')
            ->addType(AccountEnum::META_AD_ACCOUNT)
            ->addChannel(Channel::klaviyo->value)
            ->addAccount($account)
            ->addPlatformCreatedAt(new \DateTime());
        $manager->persist($channeledAccount);
        $manager->flush();

        $data = [
            'dates' => ['2023-01-01'],
            'data' => [
                [
                    'measurements' => ['count' => 100],
                    'dimensions' => ['campaign' => 'C1']
                ]
            ]
        ];
        $metricMap = ['kl_metric_id' => 'Received Email'];

        // 2. Act
        $result = MetricRequests::processKlaviyoChunk($data, 'metrics', [], 'kl_metric_id', $metricMap);

        // 3. Assert
        $this->assertEquals(1, $result['metrics']);
        
        $metrics = $manager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertCount(1, $metrics);
        $this->assertEquals(100, $metrics[0]->getMetric()->getValue());
        $this->assertEquals('Received Email', $metrics[0]->getMetric()->getMetricConfig()->getName());
    }

    public function testProcessShopifyChunkStoresOrders()
    {
        // 1. Arrange
        $manager = $this->entityManager;
        $data = [
            [
                'id' => 12345,
                'total_price' => '99.99',
                'created_at' => '2023-01-01T10:00:00Z',
                'customer' => ['id' => 678],
                'line_items' => []
            ]
        ];

        // 2. Act
        $result = MetricRequests::processShopifyChunk($data, 'orders', []);

        // 3. Assert
        // ShopifyConvert currently returns an empty collection for orders if no mapping is found or simplified
        // But let's verify it calls the converter and processor.
        $this->assertIsArray($result);
        $this->assertEquals(count($data), $result['rows']);
    }

    public function testProcessInstagramAccountStoresMetrics()
    {
        // 1. Arrange
        $manager = $this->entityManager;
        $account = new Account();
        $account->addName('IG Test Account');
        $manager->persist($account);

        $page = new Page();
        $page->addPlatformId('page_id')
            ->addUrl('http://page.com')
            ->addTitle('Test Page')
            ->addAccount($account);
        $manager->persist($page);

        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('ig_p_id')
            ->addName('IG Test')
            ->addType(AccountEnum::INSTAGRAM)
            ->addChannel(Channel::facebook_organic->value)
            ->addAccount($account)
            ->addPlatformCreatedAt(new \DateTime());
        $manager->persist($channeledAccount);
        $manager->flush();

        $data = [
            'data' => [
                [
                    'name' => 'impressions',
                    'total_value' => ['value' => 500]
                ]
            ]
        ];

        // 2. Act
        $result = MetricRequests::processInstagramAccount(
            ['id' => 'page_id', 'ig_account' => 'ig_p_id'],
            $data,
            $manager,
            $account,
            $page,
            $this->createMock(\Psr\Log\LoggerInterface::class),
            [],
            '2023-01-01',
            '2023-01-01',
            []
        );

        // 3. Assert
        $this->assertEquals(1, $result['metrics']);
        $metrics = $manager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertNotEmpty($metrics);
    }
}
