<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\EntityAggregationEngine;
use Services\Aggregation\EntityMeasureCatalog;

class EntityAggregationEngineTest extends TestCase
{
    private $connection;
    private EntityAggregationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->createSchema();
        $this->seedData();

        $this->engine = new EntityAggregationEngine($this->connection, new EntityMeasureCatalog());
    }

    private function createSchema(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                name TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                platform_id TEXT,
                channel TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_stores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                channeled_account_id INTEGER,
                channel TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER,
                platform_id TEXT,
                channeled_store_id INTEGER,
                channeled_account_id INTEGER,
                channel TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER,
                sku TEXT,
                vendor TEXT,
                product_type TEXT,
                channeled_store_id INTEGER,
                channeled_account_id INTEGER,
                channel TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER,
                channeled_account_id INTEGER,
                channeled_store_id INTEGER,
                channeled_customer_id INTEGER,
                channel TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_order_channeled_products (
                channeled_order_id INTEGER,
                channeled_product_id INTEGER
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_discounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channeled_account_id INTEGER,
                channeled_store_id INTEGER,
                channel TEXT,
                code TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_order_channeled_discounts (
                channeled_order_id INTEGER,
                channeled_discount_id INTEGER
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channeled_account_id INTEGER,
                channel TEXT,
                status TEXT,
                objective TEXT,
                buying_type TEXT,
                budget REAL,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_ad_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channeled_account_id INTEGER,
                channeled_campaign_id INTEGER,
                channel TEXT,
                status TEXT,
                optimization_goal TEXT,
                billing_event TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_ads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channeled_account_id INTEGER,
                channeled_campaign_id INTEGER,
                channeled_ad_group_id INTEGER,
                channel TEXT,
                status TEXT,
                name TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channeled_account_id INTEGER,
                page_id INTEGER,
                post_id TEXT,
                created_at TEXT,
                data TEXT
            );
        ');

        $this->connection->executeStatement('
            CREATE TABLE channeled_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id INTEGER,
                channeled_account_id INTEGER,
                channel TEXT,
                platform_created_at TEXT,
                data TEXT
            );
        ');
    }

    private function seedData(): void
    {
        // Channels (testing integer FK resolution)
        $this->connection->executeStatement("INSERT INTO channels (id, name) VALUES (1, 'mailchimp')");
        $this->connection->executeStatement("INSERT INTO channels (id, name) VALUES (2, 'shopify')");
        $this->connection->executeStatement("INSERT INTO channels (id, name) VALUES (3, 'facebook_marketing')");
        $this->connection->executeStatement("INSERT INTO channels (id, name) VALUES (4, 'klaviyo')");

        // Canonical Customers
        $this->connection->executeStatement("INSERT INTO customers (id, email, name) VALUES (101, 'alice@example.com', 'Alice')");
        $this->connection->executeStatement("INSERT INTO customers (id, email, name) VALUES (102, 'bob@example.com', 'Bob')");
        $this->connection->executeStatement("INSERT INTO customers (id, email, name) VALUES (103, 'secret@example.com', 'Secret')");

        // Accounts: Account 1 (authorized), Account 2 (unauthorized)
        $this->connection->executeStatement("INSERT INTO channeled_accounts (id, name, platform_id, channel) VALUES (1, 'Main Account', 'acc_1', 1)");
        $this->connection->executeStatement("INSERT INTO channeled_accounts (id, name, platform_id, channel) VALUES (2, 'Other Account', 'acc_2', 1)");

        // Stores: Store 1 & 2 in Account 1; Store 3 in Account 2
        $this->connection->executeStatement("INSERT INTO channeled_stores (id, name, channeled_account_id, channel) VALUES (1, 'Flagship Store', 1, 1)");
        $this->connection->executeStatement("INSERT INTO channeled_stores (id, name, channeled_account_id, channel) VALUES (2, 'Outlet Store', 1, 2)");
        $this->connection->executeStatement("INSERT INTO channeled_stores (id, name, channeled_account_id, channel) VALUES (3, 'Secret Store', 2, 1)");

        // Products
        $this->connection->executeStatement("INSERT INTO channeled_products (id, product_id, sku, channeled_store_id, channel, platform_created_at, data) VALUES (1, 501, 'SKU-SHIRT', 1, 1, '2026-01-01', '{\"price\": 29.99, \"currency_code\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_products (id, product_id, sku, channeled_store_id, channel, platform_created_at, data) VALUES (2, 502, 'SKU-PANTS', 1, 1, '2026-01-01', '{\"price\": 49.99, \"currency_code\": \"USD\"}')");

        // Customers
        $this->connection->executeStatement("INSERT INTO channeled_customers (id, customer_id, platform_id, channeled_store_id, channeled_account_id, channel, platform_created_at, data) VALUES (1, 101, 'mc_alice', 1, 1, 1, '2026-01-10 10:00:00', '{\"email\": \"alice@example.com\", \"status\": \"subscribed\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_customers (id, customer_id, platform_id, channeled_store_id, channeled_account_id, channel, platform_created_at, data) VALUES (2, 102, 'mc_bob', 1, 1, 1, '2026-02-15 11:00:00', '{\"email\": \"bob@example.com\", \"status\": \"subscribed\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_customers (id, customer_id, platform_id, channeled_store_id, channeled_account_id, channel, platform_created_at, data) VALUES (3, 103, 'mc_secret', 3, 2, 1, '2026-02-15 11:00:00', '{\"email\": \"secret@example.com\"}')");

        // Orders (using integer channel IDs 1=mailchimp, 2=shopify)
        $this->connection->executeStatement("INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data) VALUES (1, 1001, 1, 1, 1, '2026-03-01 12:00:00', '{\"total_amount\": 100.0, \"financial_status\": \"paid\", \"currency_code\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data) VALUES (2, 1002, 1, 1, 1, '2026-03-05 14:00:00', '{\"total_amount\": 150.0, \"financial_status\": \"paid\", \"currency_code\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data) VALUES (3, 1003, 1, 1, 1, '2026-03-10 16:00:00', '{\"total_amount\": 50.0, \"financial_status\": \"paid\", \"currency_code\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data) VALUES (4, 1004, 2, 2, 2, '2026-03-12 18:00:00', '{\"total_amount\": 200.0, \"financial_status\": \"refunded\", \"currency_code\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data) VALUES (5, 9999, 3, 3, 1, '2026-03-15 20:00:00', '{\"total_amount\": 5000.0, \"financial_status\": \"paid\", \"currency_code\": \"USD\"}')");

        // Order-Product Links (Line items)
        // Order 1 has Product 1 (Shirt)
        $this->connection->executeStatement("INSERT INTO channeled_order_channeled_products (channeled_order_id, channeled_product_id) VALUES (1, 1)");
        // Order 2 has Product 1 and Product 2 (Multi-item order!)
        $this->connection->executeStatement("INSERT INTO channeled_order_channeled_products (channeled_order_id, channeled_product_id) VALUES (2, 1)");
        $this->connection->executeStatement("INSERT INTO channeled_order_channeled_products (channeled_order_id, channeled_product_id) VALUES (2, 2)");

        // Campaigns & Ads & Posts
        $this->connection->executeStatement("INSERT INTO channeled_campaigns (id, channeled_account_id, channel, status, objective, budget, platform_created_at, data) VALUES (1, 1, 3, 'active', 'conversions', 500.0, '2026-02-01', '{\"currency\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_campaigns (id, channeled_account_id, channel, status, objective, budget, platform_created_at, data) VALUES (2, 1, 3, 'paused', 'brand_awareness', 250.0, '2026-02-10', '{\"currency\": \"USD\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_ads (id, channeled_account_id, channeled_campaign_id, channel, status, name, platform_created_at) VALUES (1, 1, 1, 3, 'active', 'Ad 1', '2026-02-05')");
        $this->connection->executeStatement("INSERT INTO posts (id, channeled_account_id, page_id, post_id, created_at, data) VALUES (1, 1, 10, 'fb_post_1', '2026-02-20', '{\"media_type\": \"VIDEO\"}')");

        // Discounts
        $this->connection->executeStatement("INSERT INTO channeled_discounts (id, channeled_store_id, channel, code, platform_created_at, data) VALUES (1, 1, 1, 'SPRING10', '2026-01-01', '{\"usage_count\": 45}')");

        // Events:
        // Event 1 (Mailchimp, Alice) before Alice's first order (2026-03-01)
        // Event 2 (Klaviyo, Alice) before Alice's first order (2026-03-01)
        // Event 3 (Mailchimp, Alice) AFTER Alice's first order (2026-04-01) -> should be excluded
        // Event 4 (Mailchimp, Bob) before Alice's first order (2026-02-10) -> should NOT be counted for Alice!
        $this->connection->executeStatement("INSERT INTO channeled_events (id, channeled_account_id, channel, platform_created_at, data) VALUES (1, 1, 1, '2026-02-01 10:00:00', '{\"type\": \"campaign_open\", \"email_address\": \"alice@example.com\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_events (id, channeled_account_id, channel, platform_created_at, data) VALUES (2, 1, 4, '2026-02-15 12:00:00', '{\"type\": \"automation_click\", \"email_address\": \"alice@example.com\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_events (id, channeled_account_id, channel, platform_created_at, data) VALUES (3, 1, 1, '2026-04-01 10:00:00', '{\"type\": \"campaign_click\", \"email_address\": \"alice@example.com\"}')");
        $this->connection->executeStatement("INSERT INTO channeled_events (id, channeled_account_id, channel, platform_created_at, data) VALUES (4, 1, 1, '2026-02-10 09:00:00', '{\"type\": \"campaign_open\", \"email_address\": \"bob@example.com\"}')");
    }

    public function testCalculatesTotalRevenueAndAovAcrossAuthorizedStores(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        $result = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'revenue', 'aov'],
        ], $authorized);

        $this->assertCount(1, $result['data']);
        $row = $result['data'][0];

        $this->assertEquals(4, $row['order_count']);
        $this->assertEquals(500.0, $row['revenue']);
        $this->assertEquals(125.0, $row['aov']);
        $this->assertEquals('USD', $result['meta']['currency']);
        $this->assertFalse($result['meta']['currency_mixed']);
    }

    public function testAggregatesByStoreAndChannelDimensionsWithIntegerFkResolution(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        $result = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'revenue', 'aov'],
            'dimensions' => ['channel'],
        ], $authorized);

        $this->assertCount(2, $result['data']);
        $byChannel = array_column($result['data'], null, 'channel');

        $this->assertArrayHasKey('mailchimp', $byChannel);
        $this->assertArrayHasKey('shopify', $byChannel);
        $this->assertEquals(3, $byChannel['mailchimp']['order_count']);
        $this->assertEquals(300.0, $byChannel['mailchimp']['revenue']);
        $this->assertEquals(1, $byChannel['shopify']['order_count']);
        $this->assertEquals(200.0, $byChannel['shopify']['revenue']);
    }

    public function testDeduplicatesMirroredCanonicalOrdersAcrossChannels(): void
    {
        // Insert a mirrored copy of Order 1001 ($100) into Shopify (Store 2, Channel 2)
        $this->connection->executeStatement(
            "INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data)
             VALUES (6, 1001, 2, 1, 2, '2026-03-01 12:05:00', '{\"total_amount\": 100.0, \"financial_status\": \"paid\", \"currency_code\": \"USD\"}')"
        );

        $authorized = ['mailchimp' => [['id' => 1]]];

        $result = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'canonical_order_count', 'revenue', 'aov'],
            'deduplicate_canonical' => true,
        ], $authorized);

        $row = $result['data'][0];
        // 5 channeled order rows in scope, but only 4 distinct canonical orders (1001, 1002, 1003, 1004)
        $this->assertEquals(5, $row['order_count']);
        $this->assertEquals(4, $row['canonical_order_count']);
        // Canonical revenue is still 500.0 (not 600.0!) and canonical AOV is 125.0
        $this->assertEquals(500.0, $row['revenue']);
        $this->assertEquals(125.0, $row['aov']);
    }

    public function testMultiCurrencyGuardNullsMonetaryMeasuresUntilFilteredOrGrouped(): void
    {
        // Add a EUR order in Store 1
        $this->connection->executeStatement(
            "INSERT INTO channeled_orders (id, order_id, channeled_store_id, channeled_customer_id, channel, platform_created_at, data)
             VALUES (7, 1007, 1, 1, 1, '2026-03-14 10:00:00', '{\"total_amount\": 80.0, \"financial_status\": \"paid\", \"currency_code\": \"EUR\"}')"
        );

        $authorized = ['mailchimp' => [['id' => 1]]];

        $mixedResult = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'revenue', 'aov'],
        ], $authorized);

        $this->assertEquals(5, $mixedResult['data'][0]['order_count']);
        $this->assertNull($mixedResult['data'][0]['revenue']);
        $this->assertNull($mixedResult['data'][0]['aov']);
        $this->assertTrue($mixedResult['meta']['currency_mixed']);
        $this->assertEquals(['EUR', 'USD'], $mixedResult['meta']['currencies']);

        // Filtering by currency='USD' restores numeric revenue and AOV
        $filteredResult = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'revenue', 'aov'],
            'filters' => ['currency' => 'USD'],
        ], $authorized);

        $this->assertEquals(4, $filteredResult['data'][0]['order_count']);
        $this->assertEquals(500.0, $filteredResult['data'][0]['revenue']);
        $this->assertEquals(125.0, $filteredResult['data'][0]['aov']);
        $this->assertFalse($filteredResult['meta']['currency_mixed']);
        $this->assertEquals('USD', $filteredResult['meta']['currency']);
    }

    public function testAggregatesOrdersFilteredByProductWithoutFanOut(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        // Filter orders that contained product 2 (Pants)
        $result = $this->engine->aggregate('orders', [
            'measures' => ['order_count', 'revenue'],
            'filters' => ['product' => 2],
        ], $authorized);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(1, $result['data'][0]['order_count']);
        $this->assertEquals(150.0, $result['data'][0]['revenue']);
    }

    public function testTwoStageCustomerCommerceRollupAcrossStores(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        $result = $this->engine->aggregate('customers', [
            'measures' => [
                'customer_count',
                'canonical_customer_count',
                'customer_ltv',
                'avg_revenue_per_customer',
                'repeat_customer_count',
                'high_frequency_customer_count',
                'repeat_buyer_rate',
            ],
        ], $authorized);

        $row = $result['data'][0];
        // Alice (3 orders = $300) + Bob (1 order = $200) -> 2 canonical customers, $500 total, $250 avg
        $this->assertEquals(2, $row['customer_count']);
        $this->assertEquals(2, $row['canonical_customer_count']);
        $this->assertEquals(500.0, $row['customer_ltv']);
        $this->assertEquals(250.0, $row['avg_revenue_per_customer']);
        $this->assertEquals(1, $row['repeat_customer_count']); // Alice (>1 order)
        $this->assertEquals(1, $row['high_frequency_customer_count']); // Alice (>=3 orders)
        $this->assertEquals(50.0, $row['repeat_buyer_rate']); // 1 of 2 buyers = 50%
    }

    public function testAggregatesCampaignsAndBudget(): void
    {
        $authorized = ['facebook_marketing' => [['id' => 1]]];

        $result = $this->engine->aggregate('campaigns', [
            'measures' => ['campaign_count', 'total_budget', 'average_budget'],
            'dimensions' => ['status'],
        ], $authorized);

        $this->assertCount(2, $result['data']);

        $activeRow = array_values(array_filter($result['data'], fn ($r) => $r['status'] === 'active'))[0];
        $this->assertEquals(1, $activeRow['campaign_count']);
        $this->assertEquals(500.0, $activeRow['total_budget']);

        $pausedRow = array_values(array_filter($result['data'], fn ($r) => $r['status'] === 'paused'))[0];
        $this->assertEquals(1, $pausedRow['campaign_count']);
        $this->assertEquals(250.0, $pausedRow['total_budget']);
    }

    public function testAggregatesAdsAndPosts(): void
    {
        $authorized = ['facebook_marketing' => [['id' => 1]]];

        $adRes = $this->engine->aggregate('ads', [
            'measures' => ['ad_count'],
        ], $authorized);
        $this->assertEquals(1, $adRes['data'][0]['ad_count']);

        $postRes = $this->engine->aggregate('posts', [
            'measures' => ['post_count'],
            'dimensions' => ['media_type'],
        ], $authorized);
        $this->assertEquals(1, $postRes['data'][0]['post_count']);
        $this->assertEquals('VIDEO', $postRes['data'][0]['media_type']);
    }

    public function testCustomerPurchaseFrequencyDirectlyFromAggregate(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        $res = $this->engine->aggregate('customers', [
            'measures' => ['repeat_frequency'],
        ], $authorized);

        $this->assertNotEmpty($res['data']);
        $buckets = array_column($res['data'], 'customer_count', 'order_bucket');

        $this->assertEquals(1, $buckets['1 order']);
        $this->assertEquals(1, $buckets['3 orders']);
    }

    public function testPrePurchaseTouchesIsolatesCustomerIdentityAndBreaksDownByChannel(): void
    {
        $authorized = ['mailchimp' => [['id' => 1]]];

        // Query specifically for Alice (customer_id = 101):
        // Should include Event 1 (mailchimp) and Event 2 (klaviyo), exclude Event 3 (after first order) and Event 4 (Bob's email!)
        $res = $this->engine->aggregate('customers', [
            'measures' => ['pre_purchase_touches'],
            'filters' => ['customer_id' => 101],
        ], $authorized);

        $this->assertEquals(2, $res['data']['total_touches']);
        $this->assertEquals(1, $res['data']['touched_customers']);
        $this->assertEquals(2.0, $res['data']['avg_touches_per_customer']);

        $byChannel = array_column($res['data']['by_channel'], 'touches', 'channel');
        $this->assertEquals(1, $byChannel['mailchimp'] ?? 0);
        $this->assertEquals(1, $byChannel['klaviyo'] ?? 0);
    }
}
