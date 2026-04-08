<?php

namespace Channels\Shopify;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Carbon\Carbon;
use DateTime;
use Exception;
use Classes\Conversions\ShopifyConvert;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Requests\OrderRequests;
use Classes\Requests\ProductRequests;
use Classes\Requests\CustomerRequests;

class ShopifyDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getChannel(): string
    {
        return 'shopify';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider instanceof \Core\Auth\ShopifyAuthProvider) {
            throw new Exception("Invalid or missing AuthProvider for ShopifyDriver");
        }

        if (!$this->logger) {
            $this->logger = Helpers::setLogger('shopify-driver.log');
        }

        $this->logger->info("Starting ShopifyDriver sync...");

        try {
            $api = new ShopifyApi(
                apiKey: $this->authProvider->getAccessToken(),
                shopName: $this->authProvider->getShopName(),
                version: $this->authProvider->getVersion()
            );

            // 1. Sync Orders
            $this->logger->info("Syncing Shopify Orders...");
            $api->getAllOrdersAndProcess(
                createdAtMin: $startDate->format('Y-m-d\TH:i:sP'),
                createdAtMax: $endDate->format('Y-m-d\TH:i:sP'),
                callback: function ($orders) {
                    OrderRequests::process(ShopifyConvert::orders($orders));
                }
            );

            // 2. Sync Products
            $this->logger->info("Syncing Shopify Products...");
            $api->getAllProductsAndProcess(
                callback: function ($products) {
                    ProductRequests::process(ShopifyConvert::products($products));
                }
            );

            // 3. Sync Customers
            $this->logger->info("Syncing Shopify Customers...");
            $api->getAllCustomersAndProcess(
                callback: function ($customers) {
                    CustomerRequests::process(ShopifyConvert::customers($customers));
                }
            );

            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify sync completed']));

        } catch (Exception $e) {
            $this->logger->error("ShopifyDriver error: " . $e->getMessage());
            throw $e;
        }
    }
}
