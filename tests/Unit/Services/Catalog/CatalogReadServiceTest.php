<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Catalog;

use Controllers\CatalogController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Services\Catalog\CatalogEntityReference;
use Services\Catalog\CatalogReadService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CatalogReadServiceTest extends TestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private CatalogReadService $service;
    private CatalogController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->createSchema();
        $this->seedFixtures();

        $this->service = new CatalogReadService($this->em, $this->connection);
        $this->controller = new CatalogController($this->em, $this->service);
    }

    private function createSchema(): void
    {
        $statements = [
            'CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL
            )',
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                account_id INTEGER,
                channel INTEGER NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                enabled INTEGER DEFAULT 1,
                data TEXT,
                platform_created_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                start_date TEXT,
                end_date TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                campaign_id INTEGER,
                channeled_account_id INTEGER NOT NULL,
                channel INTEGER NOT NULL,
                budget REAL DEFAULT 0,
                status TEXT,
                objective TEXT,
                buying_type TEXT,
                type TEXT,
                data TEXT,
                platform_created_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_ad_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                channeled_account_id INTEGER NOT NULL,
                campaign_id INTEGER,
                channeled_campaign_id INTEGER,
                channel INTEGER NOT NULL,
                name TEXT NOT NULL,
                start_date TEXT,
                end_date TEXT,
                status TEXT,
                optimization_goal TEXT,
                billing_event TEXT,
                targeting TEXT,
                data TEXT,
                platform_created_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE creatives (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                creative_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                data TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_ads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                channeled_account_id INTEGER NOT NULL,
                channeled_campaign_id INTEGER,
                channeled_ad_group_id INTEGER,
                creative_id INTEGER,
                channel INTEGER NOT NULL,
                name TEXT NOT NULL,
                status TEXT,
                data TEXT,
                platform_created_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE metric_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel INTEGER NOT NULL,
                name TEXT NOT NULL,
                period TEXT NOT NULL,
                account_id INTEGER,
                channeled_account_id INTEGER,
                campaign_id INTEGER,
                channeled_campaign_id INTEGER,
                channeled_ad_group_id INTEGER,
                channeled_ad_id INTEGER,
                creative_id INTEGER,
                page_id INTEGER,
                post_id INTEGER,
                query_id INTEGER,
                event_id INTEGER,
                customer_id INTEGER,
                order_id INTEGER,
                product_id INTEGER,
                dimension_set_id INTEGER,
                country_id INTEGER,
                device_id INTEGER,
                config_signature TEXT NOT NULL UNIQUE
            )',
            'CREATE TABLE pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                canonical_id INTEGER,
                url TEXT NOT NULL,
                title TEXT,
                hostname TEXT,
                platform_id TEXT NOT NULL,
                data TEXT,
                account_id INTEGER,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id TEXT NOT NULL,
                data TEXT,
                account_id INTEGER,
                channeled_account_id INTEGER,
                page_id INTEGER,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT NOT NULL,
                data TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                event_id INTEGER NOT NULL,
                channeled_account_id INTEGER NOT NULL,
                channel INTEGER NOT NULL,
                data TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE channeled_customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                platform_id TEXT NOT NULL,
                customer_id INTEGER,
                channel INTEGER NOT NULL,
                platform_created_at TEXT,
                data TEXT,
                lead_data TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
            'CREATE TABLE dimension_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            )',
            'CREATE TABLE dimension_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dimension_key_id INTEGER NOT NULL,
                value TEXT NOT NULL
            )',
            'CREATE TABLE dimension_sets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT NOT NULL UNIQUE
            )',
            'CREATE TABLE dimension_set_items (
                dimension_set_id INTEGER NOT NULL,
                dimension_value_id INTEGER NOT NULL
            )',
            'CREATE TABLE metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric_config_id INTEGER NOT NULL,
                dimensions_hash TEXT NOT NULL,
                value REAL NOT NULL,
                metric_date TEXT NOT NULL,
                metadata TEXT
            )',
        ];

        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function seedFixtures(): void
    {
        // Channels: 1 = facebook_marketing, 2 = google_ads
        $this->connection->insert('channels', ['id' => 1, 'name' => 'facebook_marketing', 'label' => 'Meta Ads']);
        $this->connection->insert('channels', ['id' => 2, 'name' => 'google_ads', 'label' => 'Google Ads']);

        // Base accounts
        $this->connection->insert('accounts', [
            'id'         => 10,
            'name'       => 'Brand Alpha Global',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-03-01 00:00:00',
        ]);
        $this->connection->insert('accounts', [
            'id'         => 20,
            'name'       => 'Brand Beta Restricted',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-03-01 00:00:00',
        ]);

        // Channeled accounts:
        // 101: Permitted Meta Ads account (act_111)
        // 102: Permitted Google Ads account (222-333-4444)
        // 103: Unauthorized Meta Ads account (act_999)
        $this->connection->insert('channeled_accounts', [
            'id'                  => 101,
            'platform_id'         => 'act_111',
            'account_id'          => 10,
            'channel'             => 1,
            'name'                => 'Alpha Meta Ad Account',
            'type'                => 'ADVERTISING',
            'enabled'             => 1,
            'data'                => json_encode(['currency' => 'USD', 'timezone_name' => 'America/New_York']),
            'platform_created_at' => '2025-11-10 10:00:00',
            'created_at'          => '2026-01-05 12:00:00',
            'updated_at'          => '2026-03-20 15:30:00',
        ]);
        $this->connection->insert('channeled_accounts', [
            'id'                  => 102,
            'platform_id'         => '2223334444',
            'account_id'          => 10,
            'channel'             => 2,
            'name'                => 'Alpha Google Ads Account',
            'type'                => 'ADVERTISING',
            'enabled'             => 1,
            'data'                => json_encode(['currency_code' => 'EUR', 'time_zone' => 'Europe/Madrid']),
            'platform_created_at' => '2025-10-01 08:00:00',
            'created_at'          => '2026-01-06 12:00:00',
            'updated_at'          => '2026-03-21 16:00:00',
        ]);
        $this->connection->insert('channeled_accounts', [
            'id'                  => 103,
            'platform_id'         => 'act_999',
            'account_id'          => 20,
            'channel'             => 1,
            'name'                => 'Secret Beta Meta Account',
            'type'                => 'ADVERTISING',
            'enabled'             => 1,
            'data'                => json_encode(['currency' => 'GBP']),
            'platform_created_at' => '2025-12-01 00:00:00',
            'created_at'          => '2026-01-10 00:00:00',
            'updated_at'          => '2026-03-22 00:00:00',
        ]);

        // Base campaigns
        $this->connection->insert('campaigns', [
            'id'          => 201,
            'campaign_id' => 'fb_camp_1',
            'name'        => 'Alpha Spring Meta Prospecting',
            'start_date'  => '2026-03-01 00:00:00',
            'end_date'    => '2026-03-31 23:59:59',
            'created_at'  => '2026-03-01 00:00:00',
            'updated_at'  => '2026-03-20 00:00:00',
        ]);
        $this->connection->insert('campaigns', [
            'id'          => 202,
            'campaign_id' => 'gads_camp_1',
            'name'        => 'Alpha Search Brand Exact',
            'start_date'  => '2026-02-15 00:00:00',
            'end_date'    => null,
            'created_at'  => '2026-02-15 00:00:00',
            'updated_at'  => '2026-03-21 00:00:00',
        ]);
        $this->connection->insert('campaigns', [
            'id'          => 203,
            'campaign_id' => 'fb_camp_secret',
            'name'        => 'Secret Beta Retargeting',
            'start_date'  => '2026-03-01 00:00:00',
            'end_date'    => null,
            'created_at'  => '2026-03-01 00:00:00',
            'updated_at'  => '2026-03-22 00:00:00',
        ]);

        // Channeled campaigns
        $this->connection->insert('channeled_campaigns', [
            'id'                   => 301,
            'platform_id'          => 'fb_camp_1',
            'campaign_id'          => 201,
            'channeled_account_id' => 101,
            'channel'              => 1,
            'budget'               => 1500.50,
            'status'               => 'ACTIVE',
            'objective'            => 'OUTCOME_SALES',
            'buying_type'          => 'AUCTION',
            'type'                 => 'regular',
            'data'                 => json_encode(['name' => 'Alpha Spring Meta Prospecting']),
            'platform_created_at'  => '2026-02-28 09:00:00',
            'created_at'           => '2026-03-01 01:00:00',
            'updated_at'           => '2026-03-20 12:00:00',
        ]);
        $this->connection->insert('channeled_campaigns', [
            'id'                   => 302,
            'platform_id'          => 'gads_camp_1',
            'campaign_id'          => 202,
            'channeled_account_id' => 102,
            'channel'              => 2,
            'budget'               => 800.00,
            'status'               => 'PAUSED',
            'objective'            => 'SALES',
            'buying_type'          => 'AUCTION',
            'type'                 => 'SEARCH',
            'data'                 => json_encode(['name' => 'Alpha Search Brand Exact']),
            'platform_created_at'  => '2026-02-14 10:00:00',
            'created_at'           => '2026-02-15 01:00:00',
            'updated_at'           => '2026-03-21 12:00:00',
        ]);
        $this->connection->insert('channeled_campaigns', [
            'id'                   => 303,
            'platform_id'          => 'fb_camp_secret',
            'campaign_id'          => 203,
            'channeled_account_id' => 103,
            'channel'              => 1,
            'budget'               => 9999.00,
            'status'               => 'ACTIVE',
            'objective'            => 'OUTCOME_LEADS',
            'buying_type'          => 'AUCTION',
            'type'                 => 'regular',
            'data'                 => json_encode(['name' => 'Secret Beta Retargeting']),
            'platform_created_at'  => '2026-03-01 00:00:00',
            'created_at'           => '2026-03-01 00:00:00',
            'updated_at'           => '2026-03-22 00:00:00',
        ]);

        // Channeled ad groups (401 = Meta Ad Set, 402 = Google Ad Group, 403 = Unauthorized Meta Ad Set)
        $this->connection->insert('channeled_ad_groups', [
            'id'                    => 401,
            'platform_id'           => 'fb_adset_1',
            'channeled_account_id'  => 101,
            'campaign_id'           => 201,
            'channeled_campaign_id' => 301,
            'channel'               => 1,
            'name'                  => 'Broad US 25-54 Purchase',
            'start_date'            => '2026-03-01 00:00:00',
            'end_date'              => '2026-03-31 23:59:59',
            'status'                => 'ACTIVE',
            'optimization_goal'     => 'OFFSITE_CONVERSIONS',
            'billing_event'         => 'IMPRESSIONS',
            'targeting'             => json_encode(['age_min' => 25, 'age_max' => 54, 'geo_locations' => ['countries' => ['US']]]),
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-02-28 09:30:00',
            'created_at'            => '2026-03-01 01:15:00',
            'updated_at'            => '2026-03-20 12:15:00',
        ]);
        $this->connection->insert('channeled_ad_groups', [
            'id'                    => 402,
            'platform_id'           => 'gads_adgroup_1',
            'channeled_account_id'  => 102,
            'campaign_id'           => 202,
            'channeled_campaign_id' => 302,
            'channel'               => 2,
            'name'                  => 'Brand Core Keywords',
            'start_date'            => '2026-02-15 00:00:00',
            'end_date'              => null,
            'status'                => 'ACTIVE',
            'optimization_goal'     => 'CLICKS',
            'billing_event'         => 'CLICKS',
            'targeting'             => null,
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-02-15 00:00:00',
            'created_at'            => '2026-02-15 01:30:00',
            'updated_at'            => '2026-03-21 12:30:00',
        ]);
        $this->connection->insert('channeled_ad_groups', [
            'id'                    => 403,
            'platform_id'           => 'fb_adset_secret',
            'channeled_account_id'  => 103,
            'campaign_id'           => 203,
            'channeled_campaign_id' => 303,
            'channel'               => 1,
            'name'                  => 'Secret Beta Ad Set',
            'start_date'            => '2026-03-01 00:00:00',
            'end_date'              => null,
            'status'                => 'ACTIVE',
            'optimization_goal'     => 'LEAD_GENERATION',
            'billing_event'         => 'IMPRESSIONS',
            'targeting'             => null,
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-03-01 00:00:00',
            'created_at'            => '2026-03-01 00:00:00',
            'updated_at'            => '2026-03-22 00:00:00',
        ]);

        // Creatives & Channeled Ads
        $this->connection->insert('creatives', [
            'id'          => 501,
            'creative_id' => 'cr_meta_1',
            'name'        => 'Spring Video Carousel',
            'data'        => json_encode(['headline' => 'Shop Spring Collection', 'image_url' => 'https://example.com/spring.jpg']),
            'created_at'  => '2026-03-01 00:00:00',
            'updated_at'  => '2026-03-20 00:00:00',
        ]);
        $this->connection->insert('channeled_ads', [
            'id'                    => 601,
            'platform_id'           => 'fb_ad_1',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => 401,
            'creative_id'           => 501,
            'channel'               => 1,
            'name'                  => 'Spring Video Ad - Variant A',
            'status'                => 'ACTIVE',
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-02-28 10:00:00',
            'created_at'            => '2026-03-01 02:00:00',
            'updated_at'            => '2026-03-20 14:00:00',
        ]);
        $this->connection->insert('channeled_ads', [
            'id'                    => 602,
            'platform_id'           => 'gads_ad_1',
            'channeled_account_id'  => 102,
            'channeled_campaign_id' => 302,
            'channeled_ad_group_id' => 402,
            'creative_id'           => null,
            'channel'               => 2,
            'name'                  => 'Responsive Search Ad #1',
            'status'                => 'ACTIVE',
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-02-15 10:00:00',
            'created_at'            => '2026-02-15 02:00:00',
            'updated_at'            => '2026-03-21 14:00:00',
        ]);
        $this->connection->insert('channeled_ads', [
            'id'                    => 603,
            'platform_id'           => 'fb_ad_secret',
            'channeled_account_id'  => 103,
            'channeled_campaign_id' => 303,
            'channeled_ad_group_id' => 403,
            'creative_id'           => null,
            'channel'               => 1,
            'name'                  => 'Secret Beta Ad',
            'status'                => 'ACTIVE',
            'data'                  => json_encode([]),
            'platform_created_at'   => '2026-03-01 00:00:00',
            'created_at'            => '2026-03-01 00:00:00',
            'updated_at'            => '2026-03-22 00:00:00',
        ]);

        // Metric configs for Campaign 301 (baseline vs breakdown pass)
        $this->connection->insert('metric_configs', [
            'id'                    => 701,
            'channel'               => 1,
            'name'                  => 'spend',
            'period'                => 'daily',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => null,
            'channeled_ad_id'       => null,
            'dimension_set_id'      => null,
            'country_id'            => null,
            'device_id'             => null,
            'config_signature'      => 'sig_camp301_spend',
        ]);
        $this->connection->insert('metric_configs', [
            'id'                    => 702,
            'channel'               => 1,
            'name'                  => 'impressions',
            'period'                => 'daily',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => null,
            'channeled_ad_id'       => null,
            'dimension_set_id'      => null,
            'country_id'            => null,
            'device_id'             => null,
            'config_signature'      => 'sig_camp301_impressions',
        ]);
        $this->connection->insert('metric_configs', [
            'id'                    => 703,
            'channel'               => 1,
            'name'                  => 'clicks',
            'period'                => 'daily',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => null,
            'channeled_ad_id'       => null,
            'dimension_set_id'      => null,
            'country_id'            => null,
            'device_id'             => null,
            'config_signature'      => 'sig_camp301_clicks',
        ]);
        $this->connection->insert('metric_configs', [
            'id'                    => 704,
            'channel'               => 1,
            'name'                  => 'purchase_value',
            'period'                => 'daily',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => null,
            'channeled_ad_id'       => null,
            'dimension_set_id'      => null,
            'country_id'            => null,
            'device_id'             => null,
            'config_signature'      => 'sig_camp301_purchase_value',
        ]);
        // Breakdown pass metric_config (has dimension_set_id = 99) -> MUST NOT be double-counted!
        $this->connection->insert('metric_configs', [
            'id'                    => 705,
            'channel'               => 1,
            'name'                  => 'spend',
            'period'                => 'daily',
            'channeled_account_id'  => 101,
            'channeled_campaign_id' => 301,
            'channeled_ad_group_id' => null,
            'channeled_ad_id'       => null,
            'dimension_set_id'      => 99,
            'country_id'            => null,
            'device_id'             => null,
            'config_signature'      => 'sig_camp301_spend_breakdown',
        ]);

        // Metrics on 2026-03-10 and 2026-03-11
        $this->connection->insert('metrics', [
            'metric_config_id' => 701,
            'dimensions_hash'  => 'base',
            'value'            => 100.0,
            'metric_date'      => '2026-03-10',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 701,
            'dimensions_hash'  => 'base',
            'value'            => 150.0,
            'metric_date'      => '2026-03-11',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 702,
            'dimensions_hash'  => 'base',
            'value'            => 5000.0,
            'metric_date'      => '2026-03-10',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 702,
            'dimensions_hash'  => 'base',
            'value'            => 5000.0,
            'metric_date'      => '2026-03-11',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 703,
            'dimensions_hash'  => 'base',
            'value'            => 120.0,
            'metric_date'      => '2026-03-10',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 703,
            'dimensions_hash'  => 'base',
            'value'            => 80.0,
            'metric_date'      => '2026-03-11',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 704,
            'dimensions_hash'  => 'base',
            'value'            => 500.0,
            'metric_date'      => '2026-03-10',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 704,
            'dimensions_hash'  => 'base',
            'value'            => 500.0,
            'metric_date'      => '2026-03-11',
        ]);
        // Breakdown pass row that would inflate spend to 500 if not excluded
        $this->connection->insert('metrics', [
            'metric_config_id' => 705,
            'dimensions_hash'  => 'age_gender',
            'value'            => 250.0,
            'metric_date'      => '2026-03-10',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function permittedScope(): array
    {
        return [
            'channels' => [
                'facebook_marketing' => [
                    'account_ids'  => [101],
                    'platform_ids' => ['111'], // tests act_111 normalization
                ],
                'google_ads' => [
                    'account_ids'  => [],
                    'platform_ids' => ['customers/222-333-4444'], // tests hyphen/prefix normalization
                ],
            ],
        ];
    }

    public function testEntityReferenceFormatAndParse(): void
    {
        $formatted = CatalogEntityReference::format('channeled', 'facebook_marketing', 'campaigns', 301);
        $this->assertSame('channeled:facebook_marketing:campaigns:301', $formatted);

        $parsed = CatalogEntityReference::parse($formatted);
        $this->assertSame('channeled', $parsed->getLayer());
        $this->assertSame('facebook_marketing', $parsed->getChannel());
        $this->assertSame('campaigns', $parsed->getEntityType());
        $this->assertSame(301, $parsed->getId());

        $this->assertNull(CatalogEntityReference::tryParse('malformed-reference'));
        $this->expectException(InvalidArgumentException::class);
        CatalogEntityReference::parse('channeled:facebook_marketing:unknown_entity:10');
    }

    public function testCapabilitiesContract(): void
    {
        $caps = $this->service->capabilities();

        $this->assertSame(1, $caps['contract_version']);
        $this->assertContains('list', $caps['supported_operations']);
        $this->assertContains('profile', $caps['supported_operations']);
        $this->assertContains('activity', $caps['supported_operations']);

        $this->assertTrue($caps['entities']['accounts']['supported']);
        $this->assertTrue($caps['entities']['campaigns']['supported']);
        $this->assertTrue($caps['entities']['ad-sets']['supported']);
        $this->assertTrue($caps['entities']['ad-groups']['supported']);
        $this->assertTrue($caps['entities']['ads']['supported']);
        $this->assertTrue($caps['entities']['pages']['supported']);
        $this->assertTrue($caps['entities']['posts']['supported']);
        $this->assertTrue($caps['entities']['queries']['supported']);
        $this->assertTrue($caps['entities']['events']['supported']);
        $this->assertTrue($caps['entities']['customers']['supported']);
        $this->assertTrue($caps['entities']['customers']['requires_pii_guard']);

        $this->assertFalse($caps['entities']['orders']['supported']);
        $this->assertFalse($caps['entities']['products']['supported']);
    }

    public function testPhase2EntitiesPagesPostsQueriesEventsAndCustomersPiiGuard(): void
    {
        // Seed channels: 3 = facebook_organic, 4 = google_search_console, 5 = google_analytics, 6 = mailchimp, 7 = facebook_leads
        $this->connection->insert('channels', ['id' => 3, 'name' => 'facebook_organic', 'label' => 'Meta Organic']);
        $this->connection->insert('channels', ['id' => 4, 'name' => 'google_search_console', 'label' => 'Google Search Console']);
        $this->connection->insert('channels', ['id' => 5, 'name' => 'google_analytics', 'label' => 'Google Analytics']);
        $this->connection->insert('channels', ['id' => 6, 'name' => 'mailchimp', 'label' => 'Mailchimp']);
        $this->connection->insert('channels', ['id' => 7, 'name' => 'facebook_leads', 'label' => 'Meta Leads']);

        $siteUrl = 'https://alpha.example.com/';
        $siteMd5 = md5($siteUrl);

        // Channeled accounts for Phase 2 channels
        $this->connection->insert('channeled_accounts', [
            'id'          => 104,
            'platform_id' => 'fb_page_104',
            'account_id'  => 10,
            'channel'     => 3,
            'name'        => 'Alpha Facebook Page',
            'type'        => 'PAGE',
            'enabled'     => 1,
        ]);
        $this->connection->insert('channeled_accounts', [
            'id'          => 105,
            'platform_id' => $siteMd5,
            'account_id'  => 10,
            'channel'     => 4,
            'name'        => 'alpha.example.com',
            'type'        => 'SITE',
            'enabled'     => 1,
        ]);
        $this->connection->insert('channeled_accounts', [
            'id'          => 106,
            'platform_id' => 'properties/445566',
            'account_id'  => 10,
            'channel'     => 5,
            'name'        => 'Alpha GA4 Property',
            'type'        => 'PROPERTY',
            'enabled'     => 1,
        ]);
        $this->connection->insert('channeled_accounts', [
            'id'          => 107,
            'platform_id' => 'mc_list_1',
            'account_id'  => 10,
            'channel'     => 6,
            'name'        => 'Alpha VIP Newsletter Audience',
            'type'        => 'AUDIENCE',
            'enabled'     => 1,
        ]);

        // Page + Post
        $this->connection->insert('pages', [
            'id'          => 801,
            'url'         => 'https://alpha.example.com/pricing',
            'title'       => 'Alpha Pricing Page',
            'hostname'    => 'alpha.example.com',
            'platform_id' => 'https://alpha.example.com/pricing',
            'account_id'  => 10,
            'created_at'  => '2026-03-01 00:00:00',
            'updated_at'  => '2026-03-20 00:00:00',
        ]);
        $this->connection->insert('posts', [
            'id'                   => 811,
            'post_id'              => '104_998877',
            'channeled_account_id' => 104,
            'page_id'              => 801,
            'data'                 => json_encode([
                'message'       => 'Introducing our new Spring Pricing tiers!',
                'media_type'    => 'IMAGE',
                'created_time'  => '2026-03-12T14:30:00+0000',
                'permalink_url' => 'https://facebook.com/104/posts/998877',
            ]),
            'created_at'           => '2026-03-12 14:35:00',
            'updated_at'           => '2026-03-20 10:00:00',
        ]);
        $this->connection->insert('metric_configs', [
            'id'                   => 710,
            'channel'              => 3,
            'name'                 => 'impressions',
            'period'               => 'lifetime',
            'channeled_account_id' => 104,
            'post_id'              => 811,
            'config_signature'     => 'sig_post_811_impr',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 710,
            'dimensions_hash'  => 'base',
            'value'            => 1250.0,
            'metric_date'      => '2026-03-12',
        ]);

        // Query + Search Type dimension
        $this->connection->insert('queries', [
            'id'         => 821,
            'query'      => 'alpha saas pricing',
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-03-20 00:00:00',
        ]);
        $this->connection->insert('dimension_keys', ['id' => 1, 'name' => 'search_type']);
        $this->connection->insert('dimension_values', ['id' => 1, 'dimension_key_id' => 1, 'value' => 'image']);
        $this->connection->insert('dimension_sets', ['id' => 1, 'hash' => 'ds_image']);
        $this->connection->insert('dimension_set_items', ['dimension_set_id' => 1, 'dimension_value_id' => 1]);

        $this->connection->insert('metric_configs', [
            'id'                   => 711,
            'channel'              => 4,
            'name'                 => 'impressions',
            'period'               => 'daily',
            'channeled_account_id' => 105,
            'query_id'             => 821,
            'dimension_set_id'     => 1,
            'config_signature'     => 'sig_q_821_impr_img',
        ]);
        $this->connection->insert('metrics', [
            'metric_config_id' => 711,
            'dimensions_hash'  => 'img',
            'value'            => 400.0,
            'metric_date'      => '2026-03-15',
        ]);

        // Event + Channeled Event + Mailchimp Campaign
        $this->connection->insert('channeled_campaigns', [
            'id'                   => 310,
            'platform_id'          => 'mc_camp_spring',
            'channeled_account_id' => 107,
            'channel'              => 6,
            'status'               => 'sent',
            'data'                 => json_encode([
                'settings'  => ['title' => 'Spring VIP Blast', 'subject_line' => 'VIP Early Access'],
                'send_time' => '2026-03-14T10:00:00Z',
            ]),
            'created_at'           => '2026-03-14 09:00:00',
            'updated_at'           => '2026-03-15 10:00:00',
        ]);
        $this->connection->insert('events', [
            'id'         => 831,
            'name'       => 'mailchimp_click',
            'created_at' => '2026-03-14 00:00:00',
            'updated_at' => '2026-03-15 00:00:00',
        ]);
        $this->connection->insert('channeled_events', [
            'id'                   => 841,
            'platform_id'          => 'evt_mc_1',
            'event_id'             => 831,
            'channeled_account_id' => 107,
            'channel'              => 6,
            'data'                 => json_encode([
                'campaign_id'   => 'mc_camp_spring',
                'action'        => 'click',
                'timestamp'     => '2026-03-14T11:15:00Z',
                'url'           => 'https://alpha.example.com/vip',
                'email_id'      => 'abcdef1234567890',
                'email_address' => 'vip@example.com',
            ]),
            'created_at'           => '2026-03-14 11:15:00',
            'updated_at'           => '2026-03-14 11:15:00',
        ]);

        // Customer
        $this->connection->insert('customers', [
            'id'         => 851,
            'email'      => 'vip@example.com',
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-03-15 00:00:00',
        ]);
        $this->connection->insert('channeled_customers', [
            'id'                  => 861,
            'platform_id'         => 'abcdef1234567890',
            'customer_id'         => 851,
            'channel'             => 6,
            'platform_created_at' => '2026-03-01 08:00:00',
            'data'                => json_encode(['full_name' => 'Jane VIP', 'status' => 'subscribed', 'vip' => true, 'audience_id' => 'mc_list_1']),
            'created_at'          => '2026-03-01 08:05:00',
            'updated_at'          => '2026-03-15 08:05:00',
        ]);

        $scopeNoPii = [
            'can_view_pii' => false,
            'channels'     => [
                'facebook_organic'      => ['account_ids' => [104], 'platform_ids' => ['fb_page_104']],
                'google_search_console' => ['account_ids' => [], 'platform_ids' => ['https://alpha.example.com']], // tests URL to MD5 expansion
                'google_analytics'      => ['account_ids' => [], 'platform_ids' => ['445566']], // tests properties/ prefix expansion
                'mailchimp'             => ['account_ids' => [107], 'platform_ids' => ['mc_list_1']],
            ],
        ];

        // 1. Pages & Posts
        $pagesList = $this->service->list('pages', ['authorized_scope' => $scopeNoPii]);
        $this->assertSame(1, $pagesList['meta']['total']);
        $this->assertSame('Alpha Pricing Page', $pagesList['data'][0]['name']);

        $postsList = $this->service->list('posts', ['authorized_scope' => $scopeNoPii]);
        $this->assertSame(1, $postsList['meta']['total']);
        $this->assertSame('Introducing our new Spring Pricing tiers!', $postsList['data'][0]['name']);

        $postProfile = $this->service->profile('posts', 'channeled:facebook_organic:posts:811', [
            'authorized_scope' => $scopeNoPii,
            'date_range'       => ['start_date' => '2026-03-01', 'end_date' => '2026-03-31'],
        ]);
        $this->assertNotNull($postProfile);
        $this->assertSame(1250.0, $postProfile['metrics']['summary']['impressions']);

        // 2. Queries with search_type filter
        $queriesImg = $this->service->list('queries', [
            'authorized_scope' => $scopeNoPii,
            'filters'          => ['search_type' => 'image'],
        ]);
        $this->assertSame(1, $queriesImg['meta']['total']);
        $this->assertSame('alpha saas pricing', $queriesImg['data'][0]['name']);

        $queriesVideo = $this->service->list('queries', [
            'authorized_scope' => $scopeNoPii,
            'filters'          => ['search_type' => 'video'],
        ]);
        $this->assertSame(0, $queriesVideo['meta']['total']);

        // 3. Mailchimp Campaign activity includes recipient occurrences with redacted email when can_view_pii = false
        $mcActivity = $this->service->activity('campaigns', 'channeled:mailchimp:campaigns:310', [
            'authorized_scope' => $scopeNoPii,
        ]);
        $this->assertNotNull($mcActivity);
        $this->assertSame('event_timeline', $mcActivity['coverage']['level']);
        $kinds = array_column($mcActivity['data'], 'kind');
        $this->assertContains('sent', $kinds);
        $this->assertContains('recipient_click', $kinds);

        // 4. Customers strictly enforce can_view_pii
        $custDeniedList = $this->service->list('customers', ['authorized_scope' => $scopeNoPii]);
        $this->assertSame(0, $custDeniedList['meta']['total']);
        $this->assertTrue($custDeniedList['meta']['pii_restricted']);

        $custDeniedProfile = $this->service->profile('customers', 'base:mailchimp:customers:851', [
            'authorized_scope' => $scopeNoPii,
        ]);
        $this->assertNull($custDeniedProfile);

        // With can_view_pii = true
        $scopeWithPii = $scopeNoPii;
        $scopeWithPii['can_view_pii'] = true;

        $custAllowedList = $this->service->list('customers', ['authorized_scope' => $scopeWithPii]);
        $this->assertSame(1, $custAllowedList['meta']['total']);
        $this->assertSame('Jane VIP (vip@example.com)', $custAllowedList['data'][0]['name']);

        $custProfile = $this->service->profile('customers', 'base:mailchimp:customers:851', [
            'authorized_scope' => $scopeWithPii,
        ]);
        $this->assertNotNull($custProfile);
        $this->assertSame('vip@example.com', $custProfile['record']['attributes']['email']);
    }

    public function testListEnforcesAuthorizedScopeAndSupportsBothAdsChannels(): void
    {
        $result = $this->service->list('campaigns', [
            'authorized_scope' => $this->permittedScope(),
        ]);

        $this->assertSame(2, $result['meta']['total']);
        $names = array_column($result['data'], 'name');
        $this->assertContains('Alpha Spring Meta Prospecting', $names);
        $this->assertContains('Alpha Search Brand Exact', $names);
        $this->assertNotContains('Secret Beta Retargeting', $names);

        // Verify channel narrowing
        $metaOnly = $this->service->list('campaigns', [
            'authorized_scope' => $this->permittedScope(),
            'filters'          => ['channel' => 'facebook_marketing'],
        ]);
        $this->assertSame(1, $metaOnly['meta']['total']);
        $this->assertSame('channeled:facebook_marketing:campaigns:301', $metaOnly['data'][0]['reference']);

        // Verify empty scope is fail-closed
        $emptyScope = $this->service->list('campaigns', [
            'authorized_scope' => ['channels' => []],
        ]);
        $this->assertSame(0, $emptyScope['meta']['total']);
        $this->assertSame([], $emptyScope['data']);
    }

    public function testListAdSetsAndAdGroupsSeparateChannels(): void
    {
        $adSets = $this->service->list('ad-sets', [
            'authorized_scope' => $this->permittedScope(),
        ]);
        $this->assertSame(1, $adSets['meta']['total']);
        $this->assertSame('Broad US 25-54 Purchase', $adSets['data'][0]['name']);
        $this->assertSame('facebook_marketing', $adSets['data'][0]['channel']);
        $this->assertSame('channeled:facebook_marketing:ad-sets:401', $adSets['data'][0]['reference']);

        $adGroups = $this->service->list('ad-groups', [
            'authorized_scope' => $this->permittedScope(),
        ]);
        $this->assertSame(1, $adGroups['meta']['total']);
        $this->assertSame('Brand Core Keywords', $adGroups['data'][0]['name']);
        $this->assertSame('google_ads', $adGroups['data'][0]['channel']);
        $this->assertSame('channeled:google_ads:ad-groups:402', $adGroups['data'][0]['reference']);
    }

    public function testListAdsSupportsSearchStatusAndParentReferenceFilters(): void
    {
        $allAds = $this->service->list('ads', [
            'authorized_scope' => $this->permittedScope(),
        ]);
        $this->assertSame(2, $allAds['meta']['total']);

        $byParentAdSet = $this->service->list('ads', [
            'authorized_scope' => $this->permittedScope(),
            'filters'          => ['parent_reference' => 'channeled:facebook_marketing:ad-sets:401'],
        ]);
        $this->assertSame(1, $byParentAdSet['meta']['total']);
        $this->assertSame('Spring Video Ad - Variant A', $byParentAdSet['data'][0]['name']);
        $this->assertSame('Spring Video Carousel', $byParentAdSet['data'][0]['attributes']['creative_name']);

        $bySearch = $this->service->list('ads', [
            'authorized_scope' => $this->permittedScope(),
            'search'           => 'Responsive Search',
        ]);
        $this->assertSame(1, $bySearch['meta']['total']);
        $this->assertSame('channeled:google_ads:ads:602', $bySearch['data'][0]['reference']);
    }

    public function testProfileResolvesHierarchyAndEntityGrainMetricsWithoutBreakdownDoubleCount(): void
    {
        $profile = $this->service->profile('campaigns', 'channeled:facebook_marketing:campaigns:301', [
            'authorized_scope' => $this->permittedScope(),
            'date_range'       => [
                'start_date' => '2026-03-01',
                'end_date'   => '2026-03-31',
            ],
        ]);

        $this->assertNotNull($profile);
        $this->assertSame('Alpha Spring Meta Prospecting', $profile['record']['name']);
        $this->assertSame('USD', $profile['record']['attributes']['currency']);
        $this->assertSame(1500.5, $profile['record']['attributes']['budget']);

        // Parent & child links
        $this->assertSame('channeled:facebook_marketing:accounts:101', $profile['relationships']['parents']['account']['reference']);
        $this->assertSame(1, $profile['relationships']['children']['ad-sets']['total_count']);
        $this->assertSame('channeled:facebook_marketing:ad-sets:401', $profile['relationships']['children']['ad-sets']['items'][0]['reference']);
        $this->assertSame(1, $profile['relationships']['children']['ads']['total_count']);

        // Metrics verification: baseline spend is 100 + 150 = 250 (breakdown pass of 250 excluded!)
        $metrics = $profile['metrics'];
        $this->assertSame('USD', $metrics['currency']);
        $this->assertSame('campaign', $metrics['grain_used']);
        $this->assertSame('partial', $metrics['coverage']['state']);
        $this->assertSame(2, $metrics['coverage']['observed_days']);
        $this->assertSame(31, $metrics['coverage']['requested_days']);
        $this->assertSame(250.0, $metrics['summary']['spend']);
        $this->assertSame(10000.0, $metrics['summary']['impressions']);
        $this->assertSame(200.0, $metrics['summary']['clicks']);
        $this->assertSame(1000.0, $metrics['summary']['purchase_value']);
        $this->assertSame(0.02, $metrics['summary']['ctr']); // 200 / 10000
        $this->assertSame(1.25, $metrics['summary']['cpc']); // 250 / 200
        $this->assertSame(25.0, $metrics['summary']['cpm']); // 250 / 10000 * 1000
        $this->assertSame(4.0, $metrics['summary']['roas_purchase']); // 1000 / 250
        $this->assertCount(2, $metrics['series']);
    }

    public function testAccountProfileFallsBackToCampaignRollupWhenNoStandaloneAccountMetricsExist(): void
    {
        $profile = $this->service->profile('accounts', 'channeled:facebook_marketing:accounts:101', [
            'authorized_scope' => $this->permittedScope(),
            'date_range'       => [
                'start_date' => '2026-03-10',
                'end_date'   => '2026-03-11',
            ],
        ]);

        $this->assertNotNull($profile);
        $this->assertSame('campaign_rollup', $profile['metrics']['grain_used']);
        $this->assertSame('complete', $profile['metrics']['coverage']['state']);
        $this->assertSame(250.0, $profile['metrics']['summary']['spend']);
    }

    public function testProfileBlocksUnauthorizedEntityAccess(): void
    {
        // Attempt to read campaign 303 belonging to account 103
        $profile = $this->service->profile('campaigns', 'channeled:facebook_marketing:campaigns:303', [
            'authorized_scope' => $this->permittedScope(),
        ]);
        $this->assertNull($profile);

        // Controller returns HTTP 404 without leaking existence
        $request = Request::create('/api/catalog/profile', 'POST', [], [], [], [], json_encode([
            'entity_type'      => 'campaigns',
            'reference'        => 'channeled:facebook_marketing:campaigns:303',
            'authorized_scope' => $this->permittedScope(),
        ]));
        $response = $this->controller->profile($request);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testActivityReturnsMilestonesSortingFilteringAndCursorPagination(): void
    {
        $activity = $this->service->activity('campaigns', 'channeled:facebook_marketing:campaigns:301', [
            'authorized_scope' => $this->permittedScope(),
            'sort'             => 'oldest',
            'limit'            => 3,
        ]);

        $this->assertNotNull($activity);
        $this->assertSame('milestones_only', $activity['coverage']['level']);
        $this->assertFalse($activity['coverage']['complete_change_history']);
        $this->assertGreaterThanOrEqual(5, $activity['meta']['total']);
        $this->assertCount(3, $activity['data']);
        $this->assertNotNull($activity['meta']['next_cursor']);

        // Fetch next page via cursor
        $page2 = $this->service->activity('campaigns', 'channeled:facebook_marketing:campaigns:301', [
            'authorized_scope' => $this->permittedScope(),
            'sort'             => 'oldest',
            'limit'            => 3,
            'cursor'           => $activity['meta']['next_cursor'],
        ]);
        $this->assertNotNull($page2);
        $this->assertNotEmpty($page2['data']);
        $this->assertNotSame($activity['data'][0]['id'], $page2['data'][0]['id']);

        // Kind filter
        $createdOnly = $this->service->activity('campaigns', 'channeled:facebook_marketing:campaigns:301', [
            'authorized_scope' => $this->permittedScope(),
            'filters'          => ['kind' => 'created'],
        ]);
        $this->assertSame(1, $createdOnly['meta']['total']);
        $this->assertSame('created', $createdOnly['data'][0]['kind']);
    }
}
