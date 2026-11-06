<?php

declare(strict_types=1);

namespace Tests\Integration;

use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
use Classes\CommerceProcessor;
use Classes\OrderProcessor;
use Core\Services\SyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use Services\Catalog\CatalogReadService;

final class MailchimpCommercePersistenceTest extends TestCase
{
    private EntityManager $manager;
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['entityManager', 'dbConfig'] as $property) {
            $this->saved[$property] = (new \ReflectionProperty(Helpers::class, $property))->getValue();
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entities'], true);
        $configuration->setAutoGenerateProxyClasses(\Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
        $this->manager = new EntityManager($connection, $configuration);
        Helpers::setEntityManager($this->manager);
        (new \ReflectionProperty(Helpers::class, 'dbConfig'))->setValue(null, ['driver' => 'pdo_pgsql']);
        (new SchemaTool($this->manager))->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $connection->insert('channels', [
            'id' => 1, 'name' => 'mailchimp', 'label' => 'Mailchimp', 'provider_id' => 1,
            'maxWorkers' => 1, 'tier' => 1, 'version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->close();
        foreach ($this->saved as $property => $value) {
            (new \ReflectionProperty(Helpers::class, $property))->setValue(null, $value);
        }
    }

    private function record(string $id, array $data): UniversalEntity
    {
        $record = new UniversalEntity();
        $record->setChannel('mailchimp')->setPlatformId($id);
        $record->data = $data;
        return $record;
    }

    private function dispatch(string $type, array $records): void
    {
        $service = new class extends SyncService {
            public function persist(string $type, ArrayCollection $records, EntityManager $manager): void
            {
                $this->processUniversalEntity($type, $records, $manager);
            }
        };
        $service->persist($type, new ArrayCollection($records), $this->manager);
    }

    public function testStoreAndOrderDispatchPreservesRelationshipsAcrossRepeatedSyncs(): void
    {
        $audience = $this->record('audience', ['account_id' => 'source-account', 'name' => 'Audience']);
        $store = $this->record('store-key', [
            'name' => 'Store', 'account_id' => 'source-account', 'audience_id' => 'audience',
            'store_platform_id' => 'store-key', 'store_id' => 'source-store', 'currency_code' => 'EUR',
        ]);
        $order = $this->record('order-key', [
            'store_platform_id' => 'store-key', 'store_id' => 'source-store',
            'account_id' => 'source-account', 'audience_id' => 'audience',
            'currency' => 'EUR', 'total_amount' => 12.5,
        ]);
        $order->platformCreatedAt = new \Carbon\Carbon('2026-11-01');
        $order->customer = (object)['id' => 'scoped-customer', 'email' => 'customer@example.test'];
        $order->lineItems = [['product_id' => 'scoped-product', 'variant_id' => 'scoped-variant']];
        $order->data['lines'] = [['quantity' => 2]];
        foreach ([1, 2] as $attempt) {
            $this->dispatch('account', [$audience]);
            $this->dispatch('store', [$store]);
            $this->dispatch('order', [$order]);
        }
        $db = $this->manager->getConnection();
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM stores'));
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_orders'));
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_orders o
            JOIN channeled_stores s ON s.id = o.channeled_store_id
            JOIN channeled_accounts a ON a.id = s.channeled_account_id'));
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_customers WHERE channeled_store_id IS NOT NULL AND channeled_account_id IS NOT NULL'));
        $scope = ['channels' => ['mailchimp' => [
            'account_ids' => [(int)$db->fetchOne('SELECT id FROM channeled_accounts')], 'platform_ids' => ['audience'],
        ]]];
        $catalog = new CatalogReadService($this->manager);
        $listed = $catalog->list('orders', ['authorized_scope' => $scope]);
        self::assertStringStartsWith('base:mailchimp:customers:', $listed['data'][0]['relationships']['customer']['reference']);
        $profile = $catalog->profile('orders', $listed['data'][0]['reference'], ['authorized_scope' => $scope]);
        self::assertSame(1, $profile['relationships']['children']['products']['total_count']);
        self::assertSame(1, $profile['relationships']['children']['variants']['total_count']);
        $order->lineItems = [];
        $order->data['lines'] = [];
        $this->dispatch('order', [$order]);
        $profile = $catalog->profile('orders', $listed['data'][0]['reference'], ['authorized_scope' => $scope]);
        self::assertSame(0, $profile['relationships']['children']['variants']['total_count']);
    }

    public function testCommerceBatchRollsBackWhenStoreAudienceIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->dispatch('store', [$this->record('missing-store', ['account_id' => 'source', 'audience_id' => 'missing'])]);
        } finally {
            self::assertSame(0, (int)$this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM stores'));
        }
    }

    public function testProductsVariantsAndCustomersKeepTheirStoreMembership(): void
    {
        $this->dispatch('account', [$this->record('audience', ['account_id' => 'source-account', 'name' => 'Audience'])]);
        $this->dispatch('store', [$this->record('store-key', [
            'name' => 'Store', 'account_id' => 'source-account', 'audience_id' => 'audience', 'store_platform_id' => 'store-key',
        ])]);
        $product = $this->record('product-key', ['store_platform_id' => 'store-key', 'title' => 'Product']);
        $product->storePlatformId = 'store-key';
        $product->variants = [(object)['platformId' => 'variant-key', 'sku' => 'sku', 'data' => ['price' => 12.5]]];
        $customer = $this->record('customer-key', ['store_platform_id' => 'store-key', 'audience_id' => 'audience']);
        $customer->email = 'customer@example.test';
        foreach ([1, 2] as $attempt) {
            $this->dispatch('product', [$product]);
            $this->dispatch('customer', [$customer]);
        }
        $db = $this->manager->getConnection();
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_product_variants'));
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_customers WHERE channeled_store_id IS NOT NULL'));
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_products WHERE channeled_store_id IS NOT NULL'));
        $cart = $this->record('cart-key', ['store_platform_id' => 'store-key', 'lines' => [['quantity' => 2]],
            'customer' => ['email_address' => 'private@example.test']]);
        $this->dispatch('cart', [$cart]);
        $this->dispatch('cart', [$cart]);
        $scope = ['channels' => ['mailchimp' => [
            'account_ids' => [(int)$db->fetchOne('SELECT id FROM channeled_accounts')], 'platform_ids' => ['audience'],
        ]]];
        $carts = (new CatalogReadService($this->manager))->list('carts', ['authorized_scope' => $scope]);
        self::assertSame(1, $carts['meta']['total']);
        self::assertSame(2, $carts['data'][0]['attributes']['item_quantity']);
        self::assertStringNotContainsString('private@example.test', json_encode($carts));
    }

    public function testCommerceCatalogAndCustomerProfilesEnforceAudienceScope(): void
    {
        foreach (['a', 'b'] as $source) {
            $this->dispatch('account', [$this->record('audience-'.$source, ['account_id' => 'source-account', 'name' => 'Audience '.$source])]);
            $this->dispatch('store', [$this->record('store-'.$source, [
                'name' => 'Store '.$source, 'account_id' => 'source-account',
                'audience_id' => 'audience-'.$source, 'store_platform_id' => 'store-'.$source,
            ])]);
            $customer = $this->record('customer-'.$source, [
                'store_platform_id' => 'store-'.$source, 'audience_id' => 'audience-'.$source,
                'full_name' => 'Customer '.$source,
            ]);
            $customer->email = 'same@example.test';
            $this->dispatch('customer', [$customer]);
        }
        $db = $this->manager->getConnection();
        $account = (int)$db->fetchOne("SELECT id FROM channeled_accounts WHERE platform_id = 'audience-a'");
        $hiddenStore = (int)$db->fetchOne("SELECT id FROM channeled_stores WHERE platform_id = 'store-b'");
        $scope = ['can_view_pii' => true, 'mode' => 'asset_group', 'channels' => [
            'mailchimp' => ['account_ids' => [$account], 'platform_ids' => ['audience-a']],
        ]];
        $service = new CatalogReadService($this->manager);
        $stores = $service->list('stores', ['authorized_scope' => $scope]);
        self::assertSame(1, $stores['meta']['total']);
        self::assertSame('Store a', $stores['data'][0]['name']);
        self::assertNull($service->profile('stores', 'channeled:mailchimp:stores:'.$hiddenStore, ['authorized_scope' => $scope]));
        $customers = $service->list('customers', ['authorized_scope' => $scope]);
        self::assertSame(1, $customers['meta']['total']);
        self::assertStringContainsString('Customer a', $customers['data'][0]['name']);
        $profile = $service->profile('customers', $customers['data'][0]['reference'], ['authorized_scope' => $scope]);
        self::assertCount(2, $profile['source_records']);
        self::assertSame(0, $service->list('customers', ['authorized_scope' => $scope, 'search' => 'Customer b'])['meta']['total']);
    }

    public function testCommerceUpgradeIsRepeatableAndAddsOnlyDeclaredSchema(): void
    {
        $db = $this->manager->getConnection();
        $db->executeStatement('DROP TABLE channeled_carts');
        $routine = new \Classes\Upgrades\Routines\Upgrade_v1_19_0_to_v1_20_0();
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $routine->up($this->manager, $output);
        $routine->up($this->manager, $output);
        self::assertTrue($db->createSchemaManager()->tablesExist(['channeled_carts', 'metrics']));
    }

    public function testPromotionCodesRemainDistinctAndAmbiguousOrderCodesAreNotLinked(): void
    {
        $this->dispatch('account', [$this->record('audience', ['account_id' => 'source-account'])]);
        $this->dispatch('store', [$this->record('store', [
            'account_id' => 'source-account', 'audience_id' => 'audience', 'store_platform_id' => 'store',
        ])]);
        foreach (['a', 'b'] as $suffix) {
            $rule = $this->record('rule-'.$suffix, ['store_platform_id' => 'store', 'title' => 'Rule '.$suffix,
                'codes' => [['id' => 'same-id', 'identity' => 'code-'.$suffix, 'code' => 'SALE']]]);
            $this->dispatch('price_rule', [$rule]);
            $this->dispatch('price_rule', [$rule]);
        }
        $db = $this->manager->getConnection();
        self::assertSame(2, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_discounts'));
        $scope = ['mode' => 'asset_group', 'channels' => ['mailchimp' => [
            'account_ids' => [(int)$db->fetchOne('SELECT id FROM channeled_accounts')], 'platform_ids' => ['audience'],
        ]]];
        $service = new CatalogReadService($this->manager);
        $codes = $service->list('promo-codes', ['authorized_scope' => $scope, 'search' => 'SALE']);
        self::assertSame(2, $codes['meta']['total']);
        self::assertNotSame($codes['data'][0]['platform_id'], $codes['data'][1]['platform_id']);
        self::assertArrayHasKey('promotion', $codes['data'][0]['relationships']);
        $ruleId = $db->fetchOne("SELECT id FROM channeled_price_rules WHERE platform_id = 'rule-a'");
        $profile = $service->profile('promotions', 'channeled:mailchimp:promotions:'.$ruleId, ['authorized_scope' => $scope]);
        self::assertSame(1, $profile['relationships']['children']['promo-codes']['total_count']);
        $order = $this->record('order', ['store_platform_id' => 'store', 'promos' => [['code' => 'SALE']]]);
        $this->dispatch('order', [$order]);
        self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_order_channeled_discounts'));
        $db->executeStatement("DELETE FROM channeled_discounts WHERE code = 'code-b'");
        $db->executeStatement("INSERT INTO channeled_discounts
            (channel, platform_id, code, channeled_price_rule_id, data, version)
            SELECT channel, 'legacy-source-id', 'legacy-identity', channeled_price_rule_id, data, 1
            FROM channeled_discounts WHERE code = 'code-a'");
        $this->dispatch('order', [$order]);
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_order_channeled_discounts'));
        $order->data['promos'] = [];
        $this->dispatch('order', [$order]);
        self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) FROM channeled_order_channeled_discounts'));
    }
}

