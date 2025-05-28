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
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests implements RequestInterface
{
    /**
     * @return Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify->value,
            Channel::klaviyo->value,
            Channel::facebook->value,
            Channel::bigcommerce->value,
            Channel::netsuite->value,
            Channel::amazon->value,
            Channel::instagram->value,
            Channel::google_analytics->value,
            Channel::pinterest->value,
            Channel::linkedin->value,
            Channel::x->value,
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
        string $createdAtMin = null,
        string $createdAtMax = null,
        array $fields = null,
        object $filters = null,
        string|bool $resume = true
    ): Response {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
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
            callback: function($customers) {
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
        string $createdAtMin = null,
        string $createdAtMax = null,
        array $fields = null,
        object $filters = null,
        string|bool $resume = true
    ): Response {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );

        $manager = Helpers::getManager();
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
            callback: function($customers) {
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
    public static function getListFromFacebook(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(object $filters = null, string|bool $resume = true): Response
    {
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
        string $createdAtMin = null,
        string $createdAtMax = null,
        object $filters = null,
        string|bool $resume = true
    ): Response {
        $config = Helpers::getChannelsConfig()['netsuite'];
        $netsuiteClient = new NetSuiteApi(
            consumerId: $config['netsuite_consumer_id'],
            consumerSecret: $config['netsuite_consumer_secret'],
            token: $config['netsuite_token_id'],
            tokenSecret: $config['netsuite_token_secret'],
            accountId: $config['netsuite_account_id'],
        );

        $manager = Helpers::getManager();
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
            callback: function($customers) {
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
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromInstagram(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromGoogleAnalytics(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromPinterest(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromLinkedIn(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    public static function getListFromX(object $filters = null, string|bool $resume = true): Response
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
            $repos = self::initializeRepositories($manager);

            foreach ($channeledCollection as $channeledCustomer) {
                self::processSingleCustomer(
                    channeledCustomer: $channeledCustomer,
                    repos: $repos,
                    manager: $manager
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

    /**
     * @param EntityManager $manager
     * @return array
     * @throws NotSupported
     */
    private static function initializeRepositories(EntityManager $manager): array
    {
        return [
            'customer' => $manager->getRepository(Customer::class),
            'channeledCustomer' => $manager->getRepository(ChanneledCustomer::class),
        ];
    }

    /**
     * @param object $channeledCustomer
     * @param array $repos
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processSingleCustomer(object $channeledCustomer, array $repos, EntityManager $manager): void
    {
        if (empty($channeledCustomer->email)) {
            return;
        }

        $cacheService = CacheService::getInstance(Helpers::getRedisClient());

        $customerEntity = self::getOrCreateCustomer(
            customer: $channeledCustomer,
            repository: $repos['customer']
        );

        $channeledCustomerEntity = self::getOrCreateChanneledCustomer(
            customerEntity: $customerEntity,
            channeledCustomer: $channeledCustomer,
            repository: $repos['channeledCustomer']
        );

        $channeledCustomerEntity = self::updateChanneledCustomerData(
            channeledCustomer: $channeledCustomer,
            channeledCustomerEntity: $channeledCustomerEntity
        );

        self::finalizeCustomerRelationships(
            customerEntity: $customerEntity,
            channeledCustomerEntity: $channeledCustomerEntity,
            manager: $manager
        );

        $entities = [
            'Customer' => $customerEntity->getEmail(),
            'ChanneledCustomer' => $channeledCustomerEntity->getPlatformId(),
        ];
        $cacheService->invalidateMultipleEntities(
            array_filter($entities, fn($value) => !empty($value)),
            Channel::from($channeledCustomer->channel)->getName()
        );
    }

    /**
     * @param object $customer
     * @param EntityRepository $repository
     * @return Customer
     */
    private static function getOrCreateCustomer(object $customer, EntityRepository $repository): Customer
    {
        return $repository->getByEmail($customer->email)
            ?? $repository->create(
                (object) ['email' => $customer->email],
                true
            );
    }

    /**
     * @param Entity $customerEntity
     * @param object $channeledCustomer
     * @param EntityRepository $repository
     * @return ChanneledCustomer
     */
    private static function getOrCreateChanneledCustomer(Entity $customerEntity, object $channeledCustomer, EntityRepository $repository): ChanneledCustomer
    {
        $channeledCustomer->customer = $customerEntity;
        return $repository->getByPlatformId($channeledCustomer->platformId, $channeledCustomer->channel)
            ?? $repository->create(
                $channeledCustomer,
                true
            );
    }

    /**
     * @param object $channeledCustomer
     * @param ChanneledCustomer $channeledCustomerEntity
     * @return ChanneledCustomer
     */
    private static function updateChanneledCustomerData(object $channeledCustomer, ChanneledCustomer $channeledCustomerEntity): ChanneledCustomer
    {
        if (empty($channeledCustomerEntity->getData())) {
            $channeledCustomerEntity
                ->addPlatformId($channeledCustomer->platformId)
                ->addPlatformCreatedAt($channeledCustomer->platformCreatedAt)
                ->addData($channeledCustomer->data);
        } else {
            $data = $channeledCustomerEntity->getData();
            $data['addresses'] = Helpers::multiDimensionalArrayUnique(
                array_merge(
                    $data['addresses'] ?? [],
                    $channeledCustomer->data['addresses'] ?? []
                )
            );
            $channeledCustomerEntity->addData($data);
        }
        return $channeledCustomerEntity;
    }

    /**
     * @param Customer $customerEntity
     * @param ChanneledCustomer $channeledCustomerEntity
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function finalizeCustomerRelationships(
        Customer $customerEntity,
        ChanneledCustomer $channeledCustomerEntity,
        EntityManager $manager
    ): void {
        $manager->persist($customerEntity);
        $manager->persist($channeledCustomerEntity);
        $manager->flush();
    }
}