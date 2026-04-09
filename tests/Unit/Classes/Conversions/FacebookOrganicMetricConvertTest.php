<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Conversions;

use Anibalealvarezs\FacebookGraphApi\Conversions\FacebookOrganicMetricConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Enums\Channel;
use Enums\Period;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Unit\BaseUnitTestCase;

class FacebookOrganicMetricConvertTest extends BaseUnitTestCase
{
    private MockObject|Page $page;
    private MockObject|Post $post;
    private MockObject|Account $account;
    private MockObject|ChanneledAccount $channeledAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = $this->createMock(Page::class);
        $this->page->method('getUrl')->willReturn($this->faker->url);

        $this->post = $this->createMock(Post::class);
        $this->post->method('getPostId')->willReturn('post' . $this->faker->randomNumber());

        $this->account = $this->createMock(Account::class);
        $this->account->method('getName')->willReturn($this->faker->word);

        $this->channeledAccount = $this->createMock(ChanneledAccount::class);
        $this->channeledAccount->method('getPlatformId')->willReturn('ca' . $this->faker->randomNumber());
    }

    public function testPageMetrics(): void
    {
        $value = $this->faker->numberBetween(1, 1000);
        $platformId = 'page' . $this->faker->randomNumber();
        $date = $this->faker->iso8601;

        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    [
                        'value' => $value,
                        'end_time' => $date
                    ]
                ]
            ]
        ];

        $result = FacebookOrganicMetricConvert::pageMetrics(
            $rows,
            $platformId,
            '',
            null,
            $this->page,
            null,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('page_impressions', $metric->name);
        $this->assertEquals($value, $metric->value);
        $this->assertEquals($platformId, $metric->platformId);
        $this->assertEquals(Channel::facebook_organic->value, $metric->channel);
    }

    public function testIgAccountMetrics(): void
    {
        $value = $this->faker->numberBetween(1000, 5000);
        $date = $this->faker->date();

        $rows = [
            [
                'name' => 'impressions',
                'total_value' => [
                    'value' => $value
                ]
            ]
        ];

        $result = FacebookOrganicMetricConvert::igAccountMetrics(
            $rows,
            $date,
            $this->page,
            $this->account,
            $this->channeledAccount,
            null,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('impressions', $metric->name);
        $this->assertEquals($value, $metric->value);
        $this->assertEquals($this->channeledAccount->getPlatformId(), $metric->platformId);
    }

    public function testIgMediaMetrics(): void
    {
        $likes = $this->faker->numberBetween(1, 100);
        $rows = [
            [
                'name' => 'likes',
                'values' => [
                    [
                        'value' => $likes
                    ]
                ]
            ]
        ];

        $result = FacebookOrganicMetricConvert::igMediaMetrics(
            $rows,
            $this->faker->date(),
            $this->page,
            $this->post,
            $this->account,
            $this->channeledAccount,
            null
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('likes', $metric->name);
        $this->assertEquals($likes, $metric->value);
        $this->assertEquals($this->post->getPostId(), $metric->platformId);
        $this->assertEquals(Period::Lifetime->value, $metric->period);
    }

    public function testRobustness(): void
    {
        // 1. Page metrics with missing 'values'
        $rows = [['name' => 'page_impressions']];
        $result = FacebookOrganicMetricConvert::pageMetrics($rows, 'p123');
        $this->assertCount(0, $result);

        // 2. IG account metrics with missing 'total_value'
        $rows = [['name' => 'impressions']];
        $result = FacebookOrganicMetricConvert::igAccountMetrics($rows, '2023-01-01', $this->page, $this->account, $this->channeledAccount);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result->first()->value);

        // 3. IG media metrics with empty 'values'
        $rows = [['name' => 'likes', 'values' => []]];
        $result = FacebookOrganicMetricConvert::igMediaMetrics($rows, '2023-01-01', $this->page, $this->post, $this->account, $this->channeledAccount);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result->first()->value);
    }
}
