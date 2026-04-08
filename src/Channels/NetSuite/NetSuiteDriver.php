<?php

namespace Channels\NetSuite;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Carbon\Carbon;
use DateTime;
use Exception;
use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Requests\CustomerRequests;
use Classes\Requests\OrderRequests;
use Classes\Requests\ProductRequests;
use Enums\Channel;

class NetSuiteDriver implements SyncDriverInterface
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
        return 'netsuite';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider instanceof \Core\Auth\NetSuiteAuthProvider) {
            throw new Exception("Invalid or missing AuthProvider for NetSuiteDriver");
        }

        if (!$this->logger) {
            $this->logger = Helpers::setLogger('netsuite-driver.log');
        }

        $jobId = $config['jobId'] ?? null;
        $resume = $config['resume'] ?? true;
        $creds = $this->authProvider->getCredentials();

        $this->logger->info("Starting NetSuiteDriver sync...");

        try {
            $api = new NetSuiteApi(
                consumerId: $creds['consumer_id'],
                consumerSecret: $creds['consumer_secret'],
                token: $creds['token_id'],
                tokenSecret: $creds['token_secret'],
                accountId: $creds['account_id']
            );

            // 1. Sync Customers
            $this->syncCustomers($api, $startDate, $endDate, $resume, $jobId);

            // 2. Sync Orders
            $this->syncOrders($api, $startDate, $endDate, $resume, $jobId, $creds);

            // 3. Sync Products
            $this->syncProducts($api, $resume, $jobId, $creds);

            return new Response(json_encode(['status' => 'success', 'message' => 'NetSuite sync completed']));

        } catch (Exception $e) {
            $this->logger->error("NetSuiteDriver error: " . $e->getMessage());
            throw $e;
        }
    }

    private function syncCustomers(NetSuiteApi $api, DateTime $start, DateTime $end, bool $resume, ?int $jobId): void
    {
        $this->logger->info("Syncing NetSuite Customers...");
        // (SuiteQL Logic from CustomerRequests)
        $query = "SELECT Customer.email, Customer.entityid, Customer.firstname, Customer.id AS customerid, Customer.lastname, Entity.datecreated, Entity.id AS entityid, Entity.isinactive FROM Customer INNER JOIN Entity ON Entity.customer = Customer.id WHERE Entity.datecreated >= TO_DATE('". $start->format('m/d/Y') ."', 'mm/dd/yyyy') AND Entity.datecreated <= TO_DATE('". $end->format('m/d/Y') ."', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($customers) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                CustomerRequests::process(NetSuiteConvert::customers($customers));
            }
        );
    }

    private function syncOrders(NetSuiteApi $api, DateTime $start, DateTime $end, bool $resume, ?int $jobId, array $creds): void
    {
        $this->logger->info("Syncing NetSuite Orders...");
        // (SuiteQL Logic from OrderRequests)
        $domain = Helpers::getDomain($creds['store_base_url'] ?? '');
        $query = "SELECT transaction.*, entity.customer as CustomerID FROM transaction INNER JOIN entity ON entity.id = transaction.entity WHERE transaction.type = 'SalesOrd' AND transaction.custbody_division_domain = '$domain' AND transaction.trandate >= TO_DATE('". $start->format('m/d/Y') ."', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($orders) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                OrderRequests::process(NetSuiteConvert::orders($orders));
            }
        );
    }

    private function syncProducts(NetSuiteApi $api, bool $resume, ?int $jobId, array $creds): void
    {
        $this->logger->info("Syncing NetSuite Products...");
        // (SuiteQL Logic from ProductRequests)
        $storeName = $creds['netsuite_store_name'] ?? '';
        $query = "SELECT Item.* FROM Item WHERE Item.isinactive = 'F'"; 
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($products) use ($api, $creds, $jobId) {
                Helpers::checkJobStatus($jobId);
                $converted = NetSuiteConvert::products($products);
                // Handle images if store_name exists
                ProductRequests::process($converted);
            }
        );
    }
}
