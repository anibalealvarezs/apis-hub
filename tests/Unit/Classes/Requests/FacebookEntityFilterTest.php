<?php

    namespace Tests\Unit\Classes\Requests;

    use Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync;
    use PHPUnit\Framework\TestCase;

    class FacebookEntityFilterTest extends TestCase
    {
        public function testGetFacebookFilterReturnsSpecificEntityFilter(): void
        {
            $config = [
                'CAMPAIGN'      => [
                    'cache_include' => 'CAMP-'
                ],
                'ADSET'         => [
                    'cache_include' => 'SET-'
                ],
                'cache_include' => 'GLOBAL-' // Old global filter
            ];

            $this->assertEquals('CAMP-', FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN'));
            $this->assertEquals('SET-', FacebookEntitySync::getFacebookFilter($config, 'ADSET'));
        }

        public function testGetFacebookFilterDoesNotFallBackToGlobalWhenSpecificMissing(): void
        {
            $config = [
                'CAMPAIGN'      => [
                    'cache_include' => 'CAMP-'
                ],
                // ADSET missing specific filter
                'cache_include' => 'GLOBAL-'
            ];

            $this->assertEquals('CAMP-', FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN'));
            $this->assertNull(FacebookEntitySync::getFacebookFilter($config, 'ADSET'));
        }

        public function testGetFacebookFilterReturnsNullWhenNothingMatches(): void
        {
            $config = [
                'CAMPAIGN' => [
                    'cache_exclude' => 'EXCLUDE-CAMP'
                ]
            ];

            $this->assertNull(FacebookEntitySync::getFacebookFilter($config, 'CAMPAIGN'));
            $this->assertNull(FacebookEntitySync::getFacebookFilter($config, 'ADSET'));
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

            $this->assertEquals('PAGE-', FacebookEntitySync::getFacebookFilter($config, 'PAGE'));
            $this->assertEquals('POST-', FacebookEntitySync::getFacebookFilter($config, 'POST'));
        }
    }
