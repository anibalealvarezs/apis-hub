<?php

    declare(strict_types=1);

    namespace Tests\Unit\Classes;

    use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
    use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator_v1_13_3;
    use Anibalealvarezs\ApiSkeleton\Enums\Country;
    use Anibalealvarezs\ApiSkeleton\Enums\Device;
    use Anibalealvarezs\ApiSkeleton\Enums\Period;
    use Tests\Unit\BaseUnitTestCase;
    use DateTime;

    class KeyGeneratorTest extends BaseUnitTestCase
    {
        // ------------------------------------------------------------------
        // Pre-existing tests
        // ------------------------------------------------------------------

        public function testGenerateMetricConfigKeyConsistency(): void
        {
            $channel = 'facebook_marketing';
            $name = $this->faker->word();
            $period = Period::Daily;
            $account = $this->faker->uuid();
            $customer = $this->faker->email();

            $key1 = KeyGenerator::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                account: $account,
                customer: $customer
            );

            $key2 = KeyGenerator::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                account: $account,
                customer: $customer
            );

            $this->assertEquals($key1, $key2, "Same inputs should yield same key");
            $this->assertEquals(32, strlen($key1), "MD5 should be 32 chars");
        }

        public function testGenerateMetricConfigKeyUniqueness(): void
        {
            $channel = 'facebook_marketing';
            $name = $this->faker->word();
            $period = Period::Daily;
            $date = $this->faker->date();
            $account = $this->faker->uuid();

            $key1 = KeyGenerator::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                account: $account,
                customer: $this->faker->email()
            );

            $key2 = KeyGenerator::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                account: $account,
                customer: $this->faker->email() // different customer
            );

            $this->assertNotEquals($key1, $key2, "Different inputs should yield different keys");
        }

        public function testGenerateMetricConfigKeyWithManyDimensions(): void
        {
            $params = [
                'channel'           => 'google_ads',
                'name'              => $this->faker->word(),
                'period'            => Period::Daily,
                'account'           => $this->faker->company(),
                'channeledAccount'  => $this->faker->uuid(),
                'campaign'          => $this->faker->sentence(),
                'channeledCampaign' => $this->faker->uuid(),
                'channeledAdGroup'  => $this->faker->uuid(),
                'channeledAd'       => $this->faker->uuid(),
                'creative'          => $this->faker->uuid(),
                'page'              => $this->faker->url(),
                'query'             => $this->faker->word(),
                'post'              => $this->faker->uuid(),
                'product'           => $this->faker->isbn13(),
                'customer'          => $this->faker->email(),
                'order'             => $this->faker->uuid(),
                'country'           => 'USA',
                'device'            => 'mobile'
            ];

            $baseKey = KeyGenerator::generateMetricConfigKey(...$params);

            foreach ($params as $key => $value) {
                $modifiedParams = $params;
                if (is_string($value)) {
                    $modifiedParams[$key] = $value.'_modified';
                } else {
                    continue;
                }

                if (in_array($key, ['channel', 'period'])) continue;

                $newKey = KeyGenerator::generateMetricConfigKey(...$modifiedParams);
                $this->assertNotEquals($baseKey, $newKey, "Modifying $key should change the hash");
            }
        }

        public function testGSCSpecialCase(): void
        {
            $dateStr = $this->faker->date();
            $date = new DateTime($dateStr);
            $key1 = KeyGenerator::generateMetricConfigKey(
                channel: 'google_search_console',
                name: 'clicks',
                period: Period::Daily,
                country: Country::USA,
                device: Device::MOBILE
            );

            $key2 = KeyGenerator::generateMetricConfigKey(
                channel: 'google_search_console',
                name: 'clicks',
                period: Period::Daily,
                country: 'USA',
                device: 'mobile'
            );

            $this->assertEquals($key1, $key2, "Enum vs String and DateTime vs String should yield same key if values match");
        }

        // ------------------------------------------------------------------
        // Signature change documentation: new generator adds 3 null keys
        // ------------------------------------------------------------------

        public function channelProvider(): array
        {
            return [
                'facebook_organic basic' => [
                    'params' => [
                        'channel' => 'facebook_organic',
                        'name' => 'page_impressions',
                        'period' => Period::Daily,
                        'page' => 'https://www.facebook.com/MyPage',
                        'post' => 'post_987654321',
                    ],
                ],
                'facebook_organic with country' => [
                    'params' => [
                        'channel' => 'facebook_organic',
                        'name' => 'page_engaged_users',
                        'period' => Period::Daily,
                        'page' => 'https://www.facebook.com/BrandPage',
                        'post' => 'post_123456789',
                        'country' => 'USA',
                    ],
                ],
                'google_search_console basic' => [
                    'params' => [
                        'channel' => 'google_search_console',
                        'name' => 'clicks',
                        'period' => Period::Daily,
                        'page' => 'https://example.com/blog/post',
                        'query' => 'best practices 2026',
                        'country' => 'USA',
                        'device' => 'mobile',
                    ],
                ],
                'google_search_console desktop' => [
                    'params' => [
                        'channel' => 'google_search_console',
                        'name' => 'impressions',
                        'period' => 'daily',
                        'page' => 'https://example.com/product/123',
                        'query' => 'buy shoes online',
                        'country' => 'ESP',
                        'device' => 'desktop',
                    ],
                ],
                'facebook_marketing full hierarchy' => [
                    'params' => [
                        'channel' => 'facebook_marketing',
                        'name' => 'reach',
                        'period' => Period::Daily,
                        'account' => 'My Business',
                        'channeledAccount' => 'act_123456789',
                        'campaign' => 'Spring Sale Campaign',
                        'channeledCampaign' => 'camp_ABC123',
                        'channeledAdGroup' => 'adset_DEF456',
                        'channeledAd' => 'ad_GHI789',
                        'creative' => 'cr_JKL012',
                    ],
                ],
                'facebook_marketing minimal' => [
                    'params' => [
                        'channel' => 'facebook_marketing',
                        'name' => 'spend',
                        'period' => 'daily',
                        'channeledAccount' => 'act_987654321',
                        'channeledCampaign' => 'camp_XYZ999',
                    ],
                ],
                'gsc with enums' => [
                    'params' => [
                        'channel' => 'google_search_console',
                        'name' => 'ctr',
                        'period' => Period::Daily,
                        'country' => Country::USA,
                        'device' => Device::MOBILE,
                    ],
                ],
                'all fields set' => [
                    'params' => [
                        'channel' => 'google_ads',
                        'name' => 'conversions',
                        'period' => Period::Daily,
                        'account' => 'Agency Account',
                        'channeledAccount' => 'cid_555',
                        'campaign' => 'Brand Campaign',
                        'channeledCampaign' => 'uc_111',
                        'channeledAdGroup' => 'ag_222',
                        'channeledAd' => 'ad_333',
                        'creative' => 'cr_444',
                        'page' => 'https://example.com/landing',
                        'query' => 'buy now',
                        'post' => 'post_999',
                        'product' => '978-3-16-148410-0',
                        'customer' => 'user@example.com',
                        'order' => 'ord_777',
                        'country' => 'DEU',
                        'device' => 'tablet',
                    ],
                ],
            ];
        }

        /**
         * @dataProvider channelProvider
         *
         * This documents the BREAKING CHANGE: the new generator produces different
         * signatures because location/state/city are added to the params JSON
         * (with null values). All existing records will have their signatures
         * recalculated by the RecalculateMetricConfigSignaturesCommand.
         */
        public function testNewGeneratorBreaksOldSignatures(array $params): void
        {
            $oldSig = KeyGenerator_v1_13_3::generateMetricConfigKey(...$params);
            $newSig = KeyGenerator::generateMetricConfigKey(...$params);

            $this->assertNotSame($oldSig, $newSig,
                "New generator intentionally breaks old signatures by adding 3 null keys to the params JSON"
            );
        }

        /**
         * @dataProvider channelProvider
         */
        public function testNewGeneratorIsDeterministic(array $params): void
        {
            $sig1 = KeyGenerator::generateMetricConfigKey(...$params);
            $sig2 = KeyGenerator::generateMetricConfigKey(...$params);

            $this->assertSame($sig1, $sig2,
                "New generator must be deterministic for same inputs"
            );
        }

        /**
         * @dataProvider channelProvider
         */
        public function testNewGeneratorUniquenessPreserved(array $params): void
        {
            $baseSig = KeyGenerator::generateMetricConfigKey(...$params);

            // Modify each string param and verify the signature changes
            foreach ($params as $key => $value) {
                if (!is_string($value) || in_array($key, ['channel', 'period'])) {
                    continue;
                }
                $modified = $params;
                $modified[$key] = $value . '_x';
                $newSig = KeyGenerator::generateMetricConfigKey(...$modified);
                $this->assertNotSame($baseSig, $newSig,
                    "Changing $key must produce a different signature in the new generator"
                );
            }
        }

        // ------------------------------------------------------------------
        // New fields change the signature when provided
        // ------------------------------------------------------------------

        public function testLocationChangesSignature(): void
        {
            $params = [
                'channel' => 'google_business_profile',
                'name' => 'calls',
                'period' => Period::Daily,
                'channeledAccount' => 'accounts/123',
            ];

            $withoutLocation = KeyGenerator::generateMetricConfigKey(...$params);
            $withLocation = KeyGenerator::generateMetricConfigKey(
                ...array_merge($params, ['location' => 'accounts/123/locations/456'])
            );

            $this->assertNotSame($withoutLocation, $withLocation,
                "Providing a location must produce a different signature"
            );
        }

        public function testStateChangesSignature(): void
        {
            $params = [
                'channel' => 'google_business_profile',
                'name' => 'views',
                'period' => 'daily',
            ];

            $without = KeyGenerator::generateMetricConfigKey(...$params);
            $with = KeyGenerator::generateMetricConfigKey(
                ...array_merge($params, ['state' => 'California'])
            );

            $this->assertNotSame($without, $with,
                "Providing a state must produce a different signature"
            );
        }

        public function testCityChangesSignature(): void
        {
            $params = [
                'channel' => 'google_business_profile',
                'name' => 'direction_requests',
                'period' => 'daily',
            ];

            $without = KeyGenerator::generateMetricConfigKey(...$params);
            $with = KeyGenerator::generateMetricConfigKey(
                ...array_merge($params, ['city' => 'San Francisco'])
            );

            $this->assertNotSame($without, $with,
                "Providing a city must produce a different signature"
            );
        }

        public function testNewFieldsCombinedChangeSignature(): void
        {
            $baseParams = [
                'channel' => 'google_business_profile',
                'name' => 'search_impressions',
                'period' => Period::Daily,
                'channeledAccount' => 'accounts/789',
            ];

            $nullSig = KeyGenerator::generateMetricConfigKey(...$baseParams);
            $fullSig = KeyGenerator::generateMetricConfigKey(
                ...array_merge($baseParams, [
                    'location' => 'accounts/789/locations/101',
                    'state' => 'California',
                    'city' => 'Los Angeles',
                ])
            );

            $this->assertNotSame($nullSig, $fullSig,
                "All three new fields together must produce a different signature"
            );
        }

        public function testNewFieldsAreIndependent(): void
        {
            $baseParams = [
                'channel' => 'google_business_profile',
                'name' => 'calls',
                'period' => 'daily',
            ];

            $locOnly = KeyGenerator::generateMetricConfigKey(
                ...array_merge($baseParams, ['location' => 'loc_1'])
            );
            $stateOnly = KeyGenerator::generateMetricConfigKey(
                ...array_merge($baseParams, ['state' => 'California'])
            );
            $cityOnly = KeyGenerator::generateMetricConfigKey(
                ...array_merge($baseParams, ['city' => 'Oakland'])
            );

            $this->assertNotSame($locOnly, $stateOnly,
                "location-only and state-only must differ"
            );
            $this->assertNotSame($locOnly, $cityOnly,
                "location-only and city-only must differ"
            );
            $this->assertNotSame($stateOnly, $cityOnly,
                "state-only and city-only must differ"
            );
        }

        public function testLocationNullEqualsAbsent(): void
        {
            $params = [
                'channel' => 'google_business_profile',
                'name' => 'calls',
                'period' => 'daily',
            ];

            $absent = KeyGenerator::generateMetricConfigKey(...$params);
            $explicitNull = KeyGenerator::generateMetricConfigKey(
                ...array_merge($params, ['location' => null, 'state' => null, 'city' => null])
            );

            $this->assertSame($absent, $explicitNull,
                "Omitted location/state/city must equal explicit null"
            );
        }

        // ------------------------------------------------------------------
        // Recalculation process simulation
        // ------------------------------------------------------------------

        public function recalculationRowDataProvider(): array
        {
            return [
                'facebook_organic row' => [
                    'row' => [
                        'channel' => 2,
                        'name' => 'page_impressions',
                        'period' => 'daily',
                        'account_name' => null,
                        'channeled_account_platform_id' => null,
                        'campaign_campaign_id' => null,
                        'channeled_campaign_platform_id' => null,
                        'channeled_ad_group_platform_id' => null,
                        'channeled_ad_platform_id' => null,
                        'creative_creative_id' => null,
                        'page_url' => 'https://www.facebook.com/MyPage',
                        'query_query' => null,
                        'post_post_id' => 'post_987654321',
                        'product_product_id' => null,
                        'customer_email' => null,
                        'order_order_id' => null,
                        'country_code' => null,
                        'device_type' => null,
                        'dimension_set_hash' => null,
                        'location_platform_id' => null,
                        'state_name' => null,
                        'city_name' => null,
                    ],
                ],
                'google_search_console row' => [
                    'row' => [
                        'channel' => 8,
                        'name' => 'clicks',
                        'period' => 'daily',
                        'account_name' => null,
                        'channeled_account_platform_id' => null,
                        'campaign_campaign_id' => null,
                        'channeled_campaign_platform_id' => null,
                        'channeled_ad_group_platform_id' => null,
                        'channeled_ad_platform_id' => null,
                        'creative_creative_id' => null,
                        'page_url' => 'https://example.com/blog/post',
                        'query_query' => 'best practices 2026',
                        'post_post_id' => null,
                        'product_product_id' => null,
                        'customer_email' => null,
                        'order_order_id' => null,
                        'country_code' => 'USA',
                        'device_type' => 'mobile',
                        'dimension_set_hash' => null,
                        'location_platform_id' => null,
                        'state_name' => null,
                        'city_name' => null,
                    ],
                ],
                'facebook_marketing row' => [
                    'row' => [
                        'channel' => 1,
                        'name' => 'reach',
                        'period' => 'daily',
                        'account_name' => 'My Business',
                        'channeled_account_platform_id' => 'act_123456789',
                        'campaign_campaign_id' => 'Spring Sale Campaign',
                        'channeled_campaign_platform_id' => 'camp_ABC123',
                        'channeled_ad_group_platform_id' => 'adset_DEF456',
                        'channeled_ad_platform_id' => 'ad_GHI789',
                        'creative_creative_id' => 'cr_JKL012',
                        'page_url' => null,
                        'query_query' => null,
                        'post_post_id' => null,
                        'product_product_id' => null,
                        'customer_email' => null,
                        'order_order_id' => null,
                        'country_code' => null,
                        'device_type' => null,
                        'dimension_set_hash' => null,
                        'location_platform_id' => null,
                        'state_name' => null,
                        'city_name' => null,
                    ],
                ],
            ];
        }

        /**
         * @dataProvider recalculationRowDataProvider
         *
         * Simulates what the recalculation command does: reads a row from the DB,
         * builds the new signature from the row's field values, and confirms
         * the process is deterministic and correct.
         */
        public function testRecalculationFromRowData(array $row): void
        {
            $newSig = KeyGenerator::generateMetricConfigKey(
                channel: $row['channel'],
                name: $row['name'],
                period: $row['period'],
                account: $row['account_name'],
                channeledAccount: $row['channeled_account_platform_id'],
                campaign: $row['campaign_campaign_id'],
                channeledCampaign: $row['channeled_campaign_platform_id'],
                channeledAdGroup: $row['channeled_ad_group_platform_id'],
                channeledAd: $row['channeled_ad_platform_id'],
                creative: $row['creative_creative_id'],
                page: $row['page_url'],
                query: $row['query_query'],
                post: $row['post_post_id'],
                product: $row['product_product_id'],
                customer: $row['customer_email'],
                order: $row['order_order_id'],
                country: $row['country_code'],
                device: $row['device_type'],
                dimensionSet: $row['dimension_set_hash'],
                location: $row['location_platform_id'],
                state: $row['state_name'],
                city: $row['city_name']
            );

            $this->assertIsString($newSig);
            $this->assertEquals(32, strlen($newSig));

            // Verify determinism
            $sameSig = KeyGenerator::generateMetricConfigKey(
                channel: $row['channel'],
                name: $row['name'],
                period: $row['period'],
                account: $row['account_name'],
                channeledAccount: $row['channeled_account_platform_id'],
                campaign: $row['campaign_campaign_id'],
                channeledCampaign: $row['channeled_campaign_platform_id'],
                channeledAdGroup: $row['channeled_ad_group_platform_id'],
                channeledAd: $row['channeled_ad_platform_id'],
                creative: $row['creative_creative_id'],
                page: $row['page_url'],
                query: $row['query_query'],
                post: $row['post_post_id'],
                product: $row['product_product_id'],
                customer: $row['customer_email'],
                order: $row['order_order_id'],
                country: $row['country_code'],
                device: $row['device_type'],
                dimensionSet: $row['dimension_set_hash'],
                location: $row['location_platform_id'],
                state: $row['state_name'],
                city: $row['city_name']
            );

            $this->assertSame($newSig, $sameSig,
                "Recalculation must be deterministic"
            );
        }

        /**
         * @dataProvider recalculationRowDataProvider
         *
         * The command skips rows where the signature hasn't changed.
         * This test verifies that a row with location/state/city = null
         * produces the same new signature every time (so skip logic works).
         */
        public function testRecalculationSkipLogic(array $row): void
        {
            $sig1 = KeyGenerator::generateMetricConfigKey(
                channel: $row['channel'],
                name: $row['name'],
                period: $row['period'],
                account: $row['account_name'],
                channeledAccount: $row['channeled_account_platform_id'],
                campaign: $row['campaign_campaign_id'],
                channeledCampaign: $row['channeled_campaign_platform_id'],
                channeledAdGroup: $row['channeled_ad_group_platform_id'],
                channeledAd: $row['channeled_ad_platform_id'],
                creative: $row['creative_creative_id'],
                page: $row['page_url'],
                query: $row['query_query'],
                post: $row['post_post_id'],
                product: $row['product_product_id'],
                customer: $row['customer_email'],
                order: $row['order_order_id'],
                country: $row['country_code'],
                device: $row['device_type'],
                dimensionSet: $row['dimension_set_hash'],
                location: $row['location_platform_id'],
                state: $row['state_name'],
                city: $row['city_name']
            );

            // Simulate "already recalculated" by generating again with same inputs
            $sig2 = KeyGenerator::generateMetricConfigKey(
                channel: $row['channel'],
                name: $row['name'],
                period: $row['period'],
                account: $row['account_name'],
                channeledAccount: $row['channeled_account_platform_id'],
                campaign: $row['campaign_campaign_id'],
                channeledCampaign: $row['channeled_campaign_platform_id'],
                channeledAdGroup: $row['channeled_ad_group_platform_id'],
                channeledAd: $row['channeled_ad_platform_id'],
                creative: $row['creative_creative_id'],
                page: $row['page_url'],
                query: $row['query_query'],
                post: $row['post_post_id'],
                product: $row['product_product_id'],
                customer: $row['customer_email'],
                order: $row['order_order_id'],
                country: $row['country_code'],
                device: $row['device_type'],
                dimensionSet: $row['dimension_set_hash'],
                location: $row['location_platform_id'],
                state: $row['state_name'],
                city: $row['city_name']
            );

            $this->assertSame($sig1, $sig2,
                "Skip logic: same row data must produce same signature every time"
            );
        }

        // ------------------------------------------------------------------
        // Stability: new fields do not corrupt existing fields
        // ------------------------------------------------------------------

        public function testExistingFieldsStillDifferentiateWithNewFields(): void
        {
            $base = [
                'channel' => 'google_business_profile',
                'name' => 'calls',
                'period' => 'daily',
                'channeledAccount' => 'accounts/123',
                'location' => 'accounts/123/locations/456',
            ];

            $keyA = KeyGenerator::generateMetricConfigKey(...$base);
            $keyB = KeyGenerator::generateMetricConfigKey(
                ...array_merge($base, ['channeledAccount' => 'accounts/999'])
            );

            $this->assertNotSame($keyA, $keyB,
                "Changing channeledAccount must still change signature even when location is set"
            );
        }

        public function testNewFieldsDefaultToNullDoNotBreak(): void
        {
            $sig = KeyGenerator::generateMetricConfigKey(
                channel: 'google_business_profile',
                name: 'calls',
                period: 'daily'
            );

            $this->assertIsString($sig);
            $this->assertEquals(32, strlen($sig));
        }
    }