<?php

declare(strict_types=1);

namespace Tests\Integration\Conversions;

use Anibalealvarezs\FacebookGraphApi\Conversions\FacebookOrganicMetricConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Page;
use Enums\Period;
use Tests\Integration\BaseIntegrationTestCase;

class FacebookOrganicMetricIntegrationTest extends BaseIntegrationTestCase
{
    public function testPageMetricsTransformsDataCorrectly(): void
    {
        $pagePlatformId = $this->faker->uuid;
        $pageEntity = new Page();
        $pageEntity->addUrl($this->faker->url);
        $pageEntity->addPlatformId($pagePlatformId);
        $this->entityManager->persist($pageEntity);
        $this->entityManager->flush();

        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    ['value' => $this->faker->numberBetween(100, 10000), 'end_time' => $this->faker->iso8601],
                    ['value' => $this->faker->numberBetween(100, 10000), 'end_time' => $this->faker->iso8601]
                ]
            ]
        ];

        $collection = FacebookOrganicMetricConvert::pageMetrics(
            rows: $rows,
            pagePlatformId: $pagePlatformId,
            postPlatformId: '',
            logger: null,
            page: $pageEntity,
            post: null,
            period: Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    public function testIgAccountMetricsTransformsDataCorrectly(): void
    {
        $accountEntity = new Account();
        $accountEntity->addName($this->faker->name . ' Account');
        $this->entityManager->persist($accountEntity);
        
        $igPlatformId = 'ig_' . $this->faker->numerify('#####');
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($igPlatformId);
        $channeledAccount->addName($this->faker->userName);
        $channeledAccount->addType(\Enums\Account::INSTAGRAM);
        $channeledAccount->addChannel(\Enums\Channel::facebook_organic->value);
        $channeledAccount->addAccount($accountEntity);
        $this->entityManager->persist($channeledAccount);
        $this->entityManager->flush();

        $rows = [
            [
                'name' => 'follower_count',
                'total_value' => [
                    'breakdowns' => [
                        [
                            'dimension_keys' => ['country'],
                            'results' => [
                                ['dimension_values' => [$this->faker->countryCode], 'value' => $this->faker->numberBetween(1, 1000)],
                                ['dimension_values' => [$this->faker->countryCode], 'value' => $this->faker->numberBetween(1, 1000)]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'name' => 'impressions',
                'total_value' => ['value' => $this->faker->numberBetween(1, 10000)]
            ]
        ];

        $collection = FacebookOrganicMetricConvert::igAccountMetrics(
            rows: $rows,
            date: $this->faker->date(),
            page: null,
            account: $accountEntity,
            channeledAccount: $channeledAccount,
            logger: null,
            period: Period::Daily
        );

        $this->assertCount(4, $collection);
    }
}
