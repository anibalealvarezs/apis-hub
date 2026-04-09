<?php

declare(strict_types=1);

namespace Tests\Integration\Requests;

use Classes\Requests\MetricRequests;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledMetric;
use Enums\Channel;
use Tests\Integration\BaseIntegrationTestCase;
use Doctrine\Common\Collections\ArrayCollection;
use stdClass;

class ModularMetricRequestsTest extends BaseIntegrationTestCase
{
    /**
     * Test the universal persistence layer.
     */
    public function testPersistGenericCollection()
    {
        // 1. Arrange
        $manager = $this->entityManager;
        
        $account = new Account();
        $account->addName('Modular Store');
        $manager->persist($account);
        $manager->flush();

        // Simulate a modular metric collection from ANY driver (using stdClass as per SDK)
        $metrics = new ArrayCollection();
        
        $m1 = new stdClass();
        $m1->name = 'Total Revenue';
        $m1->value = 1500.50;
        $m1->channel = Channel::shopify->value;
        $m1->account = 'Modular Store';
        $m1->platformCreatedAt = new \DateTime('2023-01-01 10:00:00');
        $m1->metricDate = '2023-01-01';
        $m1->platformId = 'test_p_id';
        $m1->period = \Enums\Period::Daily->value;
        $m1->dimensions = [];
        $m1->dimensionsHash = md5('[]');
        $m1->metricConfigKey = 'shop_revenue_mod';
        $m1->metadata = []; // Added required metadata field
        
        $metrics->add($m1);

        // 2. Act
        $result = MetricRequests::persist($metrics);

        // 3. Assert
        $this->assertEquals(1, $result['metrics']);
        
        $persisted = $manager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertCount(1, $persisted);
        $this->assertEquals(1500.50, $persisted[0]->getMetric()->getValue());
        $this->assertEquals(Channel::shopify->value, $persisted[0]->getChannel());
    }
}
