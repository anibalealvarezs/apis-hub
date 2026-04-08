<?php

declare(strict_types=1);

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\Conversions\KlaviyoConvert;
use Classes\Conversions\NetSuiteConvert;
use Classes\Conversions\ShopifyConvert;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Analytics\Customer;
use Entities\Entity;
use Enums\Channel;
use Repositories\CustomerRepository;
use Repositories\Channeled\ChanneledCustomerRepository;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests implements RequestInterface
{
    /**
     * @return \Enums\Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::klaviyo,
            Channel::facebook_marketing,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
            Channel::instagram,
            Channel::google_analytics,
            Channel::pinterest,
            Channel::linkedin,
            Channel::x,
        ];
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     */
    public static function getListFromShopify(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
        /** @var ChanneledCustomerRepository $channeledCustomerRepository */
        $channeledCustomerRepository = $manager->getRepository(ChanneledCustomer::class);
        $lastChanneledCustomer = $channeledCustomerRepository->getLastByPlatformId(Channel::shopify->value);

        $shopifyClient->getAllCustomersAndProcess(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            fields: $fields,
            ids: $filters->ids ?? null,
            sinceId: $filters->sinceId ?? (isset($lastChanneledCustomer['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledCustomer['platformId'] : null),
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
            callback: function ($customers) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(ShopifyConvert::customers($customers));
            }
        );
        return new Response(json_encode(['Customers retrieved']));
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     */
    public static function getListFromKlaviyo(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );

        $manager = Helpers::getManager();
        /** @var ChanneledCustomerRepository $channeledCustomerRepository */
        $channeledCustomerRepository = $manager->getRepository(ChanneledCustomer::class);
        $lastChanneledCustomer = $channeledCustomerRepository->getLastByPlatformCreatedAt(Channel::klaviyo->value);

        $origin = Carbon::parse("2000-01-01");
        $min = $createdAtMin ? Carbon::parse($createdAtMin) : (isset($lastChanneledCustomer['platformCreatedAt']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? Carbon::parse($lastChanneledCustomer['platformCreatedAt']) : null);
        $max = $createdAtMax ? Carbon::parse($createdAtMax) : null;
        $now = Carbon::now();
        $from = $min && $min->lt($now) && $min->lt($max) && $origin->lte($min) ?
            $min->format('Y-m-d H:i:s') :
            $origin->format("Y-m-d H:i:s");
        $to = $max && $max->lte($now) ?
            $max->format('Y-m-d H:i:s') :
            $now->format('Y-m-d H:i:s');
        $formattedFilters = [];
        if ($filters) {
            foreach ($filters as $key => $value) {
                $formattedFilters[] = [
                    "operator" => 'equals',
                    "field" => $key,
                    "value" => $value,
                ];
            }
        }
        $klaviyoClient->getAllProfilesAndProcess(
            profileFields: $fields,
            additionalFields: ['predictive_analytics', 'subscriptions'],
            filter: [
                ...$formattedFilters,
                ...[
                    ["operator" => "greater-than", "field" => "created", "value" => $from],
                    ["operator" => "less-than", "field" => "created", "value" => $to],
                ]
            ],
            sortField: 'created',
            callback: function ($customers) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(KlaviyoConvert::customers($customers));
            }
        );
        return new Response(json_encode(['Customers retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromFacebookMarketing(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                $driver = \Core\Drivers\DriverFactory::get('bigcommerce');
                $startDate = $createdAtMin ? new \DateTime($createdAtMin) : new \DateTime('-30 days');
                $endDate = $createdAtMax ? new \DateTime($createdAtMax) : new \DateTime();

                return $driver->sync($startDate, $endDate, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                ]);
            } catch (\Exception $e) {
                // Fallback
            }
        }

        return new Response(json_encode([]));
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     */
    public static function getListFromNetSuite(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                $driver = \Core\Drivers\DriverFactory::get('netsuite');
                $startDate = $createdAtMin ? new \DateTime($createdAtMin) : new \DateTime('-30 days');
                $endDate = $createdAtMax ? new \DateTime($createdAtMax) : new \DateTime();

                return $driver->sync($startDate, $endDate, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'customers'
                ]);
            } catch (\Exception $e) {
                // Fallback
            }
        }

        $config = Helpers::getChannelsConfig()['netsuite'];
        $netsuiteClient = new NetSuiteApi(
            consumerId: $config['netsuite_consumer_id'],
            consumerSecret: $config['netsuite_consumer_secret'],
            token: $config['netsuite_token_id'],
            tokenSecret: $config['netsuite_token_secret'],
            accountId: $config['netsuite_account_id'],
        );

        $manager = Helpers::getManager();
        /** @var ChanneledCustomerRepository $channeledCustomerRepository */
        $channeledCustomerRepository = $manager->getRepository(ChanneledCustomer::class);
        $lastChanneledCustomer = $channeledCustomerRepository->getLastByPlatformId(Channel::netsuite->value);

        $query = "SELECT
                Customer.email,
                Customer.entityid,
                Customer.firstname,
                Customer.id AS customerid,
                Customer.lastname,
                customerAddressbookEntityAddress.addr1 as AddressAddr1,
                customerAddressbookEntityAddress.addr2 as AddressAddr2,
                customerAddressbookEntityAddress.city as AddressCity,
                customerAddressbookEntityAddress.country as AddressCountry,
                customerAddressbookEntityAddress.state as AddressState,
                customerAddressbookEntityAddress.dropdownstate as AddressDropdownState,
                customerAddressbookEntityAddress.zip as AddressZip,
                customerAddressbookEntityAddress.addressee as AddressAddressee,
                customerAddressbookEntityAddress.addrphone as AddressPhone,
                customerAddressbookEntityAddress.addrtext as AddressText,
                customerAddressbookEntityAddress.attention as AddressAttention,
                customerAddressbookEntityAddress.override as AddressOverride,
                customerAddressbookEntityAddress.recordowner as AddressRecordOwner,
                Entity.altname,
                Entity.contact,
                Entity.datecreated,
                Entity.entitytitle,
                Entity.group,
                Entity.id AS entityid,
                Entity.isinactive,
                Entity.isperson,
                Entity.lastmodifieddate,
                Entity.parent,
                Entity.phone,
                Entity.title,
                Entity.toplevelparent,
                Entity.type
            FROM Customer
            INNER JOIN Entity
                ON Entity.customer = Customer.id
            INNER JOIN customerAddressbook
                ON Customer.id = customerAddressbook.entity
            LEFT JOIN customerAddressbookEntityAddress
                ON customerAddressbook.addressbookaddress = customerAddressbookEntityAddress.nkey
            WHERE Entity.datecreated >= TO_DATE('". ($createdAtMin ? Carbon::parse($createdAtMin)->format('m/d/Y') : '01/01/1989') ."', 'mm/dd/yyyy')
                AND Entity.datecreated <= TO_DATE('". ($createdAtMax ? Carbon::parse($createdAtMax)->format('m/d/Y') : '01/01/2099') ."', 'mm/dd/yyyy')
                AND Entity.id > " . (isset($lastChanneledCustomer['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledCustomer['platformId'] : 0);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $query .= " AND Entity.$key = '$value'";
            }
        }
        $query .= " ORDER BY Entity.id ASC";
        $netsuiteClient->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($customers) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(NetSuiteConvert::customers($customers));
            }
        );
        return new Response(json_encode(['Customers retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromInstagram(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromGoogleAnalytics(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromPinterest(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromLinkedIn(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    public static function getListFromX(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function process(ArrayCollection $channeledCollection): Response
    {
        try {
            $manager = Helpers::getManager();

            $result = \Classes\CustomerProcessor::processCustomers($channeledCollection, $manager);

            if (!empty($result)) {
                $cacheService = CacheService::getInstance(Helpers::getRedisClient());
                $entities = [
                    'Customer' => $result['emails'],
                    'ChanneledCustomer' => $result['platformIds'],
                ];

                // Taking the first channel processed
                $channelName = Channel::from(reset($result['channels']))->getName();

                $cacheService->invalidateMultipleEntities(
                    array_filter($entities, fn ($value) => !empty($value)),
                    $channelName
                );
            }

            return new Response(json_encode(['Customers processed']));
        } catch (Exception $e) {
            return new Response(
                json_encode(['error' => $e->getMessage()]),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
