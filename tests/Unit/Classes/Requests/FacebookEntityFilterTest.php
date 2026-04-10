<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\MetricRequests;
use PHPUnit\Framework\TestCase;

class FacebookEntityFilterTest extends TestCase
{
    public function testGetFacebookFilterReturnsSpecificEntityFilter(): void
    {
        $config = [
            'CAMPAIGN' => [
                'cache_include' => 'CAMP-'
            ],
            'ADSET' => [
                'cache_include' => 'SET-'
            ],
            'cache_include' => 'GLOBAL-' // Old global filter
        ];

                $this->assertEquals('CAMP-', \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN', 'cache_include'));
        $this->assertEquals('SET-', \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'ADSET', 'cache_include'));
    }

    public function testGetFacebookFilterDoesNotFallBackToGlobalWhenSpecificMissing(): void
    {
        $config = [
            'CAMPAIGN' => [
                'cache_include' => 'CAMP-'
            ],
            // ADSET missing specific filter
            'cache_include' => 'GLOBAL-'
        ];

                $this->assertEquals('CAMP-', \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN', 'cache_include'));
        $this->assertNull(\Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'ADSET', 'cache_include'));
    }

    public function testGetFacebookFilterReturnsNullWhenNothingMatches(): void
    {
        $config = [
            'CAMPAIGN' => [
                'cache_exclude' => 'EXCLUDE-CAMP'
            ]
        ];

                $this->assertNull(\Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN', 'cache_include'));
        $this->assertNull(\Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'ADSET', 'cache_include'));
    }

    public function testGetFacebookFilterWorksForOrganicEntities(): void
    {
        $config = [
            'PAGE' => [
                'cache_include' => 'PAGE-'
            ],
            'POST' => [
                'cache_include' => 'POST-'
            ]
        ];

                $this->assertEquals('PAGE-', \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'PAGE', 'cache_include'));
        $this->assertEquals('POST-', \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($config, 'POST', 'cache_include'));
    }
}
