<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use Classes\KeyGenerator;
use Enums\Channel;
use Enums\Period;
use Tests\Unit\BaseUnitTestCase;
use DateTime;

class KeyGeneratorTest extends BaseUnitTestCase
{
    public function testGenerateMetricConfigKeyConsistency(): void
    {
        $channel = Channel::facebook_marketing;
        $name = $this->faker->word();
        $period = Period::Daily;
        $date = $this->faker->date();
        $account = $this->faker->uuid();
        $customer = $this->faker->email();

        $key1 = KeyGenerator::generateMetricConfigKey(
            channel: $channel,
            name: $name,
            period: $period,
            metricDate: $date,
            account: $account,
            customer: $customer
        );

        $key2 = KeyGenerator::generateMetricConfigKey(
            channel: $channel,
            name: $name,
            period: $period,
            metricDate: $date,
            account: $account,
            customer: $customer
        );

        $this->assertEquals($key1, $key2, "Same inputs should yield same key");
        $this->assertEquals(32, strlen($key1), "MD5 should be 32 chars");
    }

    public function testGenerateMetricConfigKeyUniqueness(): void
    {
        $channel = Channel::facebook_marketing;
        $name = $this->faker->word();
        $period = Period::Daily;
        $date = $this->faker->date();
        $account = $this->faker->uuid();

        $key1 = KeyGenerator::generateMetricConfigKey(
            channel: $channel,
            name: $name,
            period: $period,
            metricDate: $date,
            account: $account,
            customer: $this->faker->email()
        );

        $key2 = KeyGenerator::generateMetricConfigKey(
            channel: $channel,
            name: $name,
            period: $period,
            metricDate: $date,
            account: $account,
            customer: $this->faker->email() // different customer
        );

        $this->assertNotEquals($key1, $key2, "Different inputs should yield different keys");
    }

    public function testGenerateMetricConfigKeyWithManyDimensions(): void
    {
        // Testing that all fields are indeed considered
        $params = [
            'channel' => Channel::google_ads,
            'name' => $this->faker->word(),
            'period' => Period::Daily,
            'metricDate' => $this->faker->date(),
            'account' => $this->faker->company(),
            'channeledAccount' => $this->faker->uuid(),
            'campaign' => $this->faker->sentence(),
            'channeledCampaign' => $this->faker->uuid(),
            'channeledAdGroup' => $this->faker->uuid(),
            'channeledAd' => $this->faker->uuid(),
            'creative' => $this->faker->uuid(),
            'page' => $this->faker->url(),
            'query' => $this->faker->word(),
            'post' => $this->faker->uuid(),
            'product' => $this->faker->isbn13(),
            'customer' => $this->faker->email(),
            'order' => $this->faker->uuid(),
            'country' => 'USA',
            'device' => 'mobile'
        ];

        $baseKey = KeyGenerator::generateMetricConfigKey(...$params);

        foreach ($params as $key => $value) {
            $modifiedParams = $params;
            if (is_string($value)) {
                $modifiedParams[$key] = $value . '_modified';
            } else {
                continue; // Skip Enums for this specific modification test
            }
            
            // Skip the ones that might have special formatting if modified blindly
            if (in_array($key, ['channel', 'period', 'metricDate'])) continue;

            $newKey = KeyGenerator::generateMetricConfigKey(...$modifiedParams);
            $this->assertNotEquals($baseKey, $newKey, "Modifying $key should change the hash");
        }
    }

    public function testGSCSpecialCase(): void
    {
        $dateStr = $this->faker->date();
        $date = new DateTime($dateStr);
        $key1 = KeyGenerator::generateMetricConfigKey(
            channel: Channel::google_search_console,
            name: 'clicks',
            period: Period::Daily,
            metricDate: $date,
            country: \Enums\Country::USA,
            device: \Enums\Device::MOBILE
        );

        $key2 = KeyGenerator::generateMetricConfigKey(
            channel: Channel::google_search_console,
            name: 'clicks',
            period: Period::Daily,
            metricDate: $dateStr, // string date
            country: 'USA', // match enum value 'USA'
            device: 'mobile' // match enum value 'mobile'
        );

        $this->assertEquals($key1, $key2, "Enum vs String and DateTime vs String should yield same key if values match");
    }



}
