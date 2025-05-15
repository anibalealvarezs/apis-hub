<?php

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channeled\ChanneledOrder;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductVariant;
use Entities\Analytics\Order;
use Enums\Channels;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class OrderRequests implements RequestInterface
{
    /**
     * @param string|null $processedAtMin
     * @param string|null $processedAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromShopify(string $processedAtMin = null, string $processedAtMax = null, array $fields = null, object $filters = null, string|bool $resume = true): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
        $channeledOrderRepository = $manager->getRepository(entityName: ChanneledOrder::class);
        $lastChanneledOrder = $channeledOrderRepository->getLastByPlatformId(channel: Channels::shopify->value);

        $shopifyClient->getAllOrdersAndProcess(
            createdAtMin: $filters->createdAtMin ?? null,
            createdAtMax: $filters->createdAtMax ?? null,
            fields: $fields, // Example: ["id", "processed_at", "total_price", "total_discounts", "discount_codes", "customer", "line_items"]
            financialStatus: $filters->financialStatus ?? null,
            fulfillmentStatus: $filters->fulfillmentStatus ?? null,
            ids: $filters->ids ?? null,
            processedAtMin: $processedAtMin,
            processedAtMax: $processedAtMax,
            sinceId: $filters->sinceId ?? isset($lastChanneledOrder['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledOrder['platformId'] : null,
            status: $filters->status ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
            callback: function($orders) {
                self::process(ShopifyConvert::orders($orders));
            }
        );
        return new Response(json_encode(['Orders retrieved']));
    }

    /**
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromKlaviyo(array $fields = null, object $filters = null, string|bool $resume = true): Response
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
     * @param string $fromDate
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromNetsuite(string $fromDate = '01/01/1999', object $filters = null, string|bool $resume = true): Response
    {
        $config = Helpers::getChannelsConfig()['netsuite'];
        $netsuiteClient = new NetSuiteApi(
            consumerId: $config['netsuite_consumer_id'],
            consumerSecret: $config['netsuite_consumer_secret'],
            token: $config['netsuite_token_id'],
            tokenSecret: $config['netsuite_token_secret'],
            accountId: $config['netsuite_account_id'],
        );

        $manager = Helpers::getManager();
        $channeledOrderRepository = $manager->getRepository(entityName: ChanneledOrder::class);
        $lastChanneledOrder = $channeledOrderRepository->getLastByPlatformId(channel: Channels::netsuite->value);

        $query = "SELECT
                transaction.*,
                entity.customer as CustomerID,
                customer.email as CustomerEmail,
                CUSTOMLIST_NLI_STATUS.id as NliStatusID,
                CUSTOMLIST_SOS_TYPE.id as SosTypeID,
                Item.id as ItemID,
                Item.itemid as ItemSku,
                Item.custitem_web_store_design_item as ItemWebStoreDesignItem,
                Item.parent as ItemParent,
                promotionCode.name AS PromotionCodeName,
                transactionBillingAddress.addr1 AS billingAddress1,
                transactionBillingAddress.addr2 AS billingAddress2,
                transactionBillingAddress.addrtext AS billingAddressText,
                transactionBillingAddress.city AS billingCity,
                transactionBillingAddress.country AS billingCountry,
                transactionBillingAddress.dropdownstate AS billingDropdownState,
                transactionBillingAddress.recordowner AS billingRecordOwner,
                transactionBillingAddress.state AS billingState,
                transactionBillingAddress.zip AS billingZip,
                TransactionLine.actualshipdate as TransactionLineActualShipDate,
                TransactionLine.closedate as TransactionLineCloseDate,
                TransactionLine.costestimate as TransactionLineCostEstimate,
                TransactionLine.costestimaterate as TransactionLineCostEstimateRate,
                TransactionLine.costestimatetype as TransactionLineCostEstimateType,
                TransactionLine.creditforeignamount as TransactionLineCreditForeignAmount,
                TransactionLine.custcol_design_code as TransactionLineDesignCode,
                TransactionLine.custcol_design_market as TransactionLineDesignMarket,
                TransactionLine.custcol_promo_code as TransactionLinePromoCode,
                TransactionLine.estgrossprofit as TransactionLineEstGrossProfit,
                TransactionLine.estgrossprofitpercent as TransactionLineEstGrossProfitPercent,
                TransactionLine.expenseaccount as TransactionLineExpenseAccount,
                TransactionLine.expectedshipdate as TransactionLineExpectedShipDate,
                TransactionLine.foreignamount as TransactionLineForeignAmount,
                TransactionLine.id as TransactionLineID,
                TransactionLine.isclosed as TransactionLineIsClosed,
                TransactionLine.isfullyshipped as TransactionLineIsFullyShipped,
                TransactionLine.itemtype as TransactionLineItemType,
                TransactionLine.linelastmodifieddate as TransactionLineLastModifiedDate,
                TransactionLine.linesequencenumber as TransactionLineSequenceNumber,
                TransactionLine.memo as TransactionLineMemo,
                TransactionLine.netamount as TransactionLineNetAmount,
                TransactionLine.price as TransactionLinePrice,
                TransactionLine.quantity as TransactionLineQuantity,
                TransactionLine.quantitybackordered as TransactionLineQuantityBackordered,
                TransactionLine.quantitybilled as TransactionLineQuantityBilled,
                TransactionLine.quantitypacked as TransactionLineQuantityPacked,
                TransactionLine.quantitypicked as TransactionLineQuantityPicked,
                TransactionLine.quantityrejected as TransactionLineQuantityRejected,
                TransactionLine.quantityshiprecv as TransactionLineQuantityShipRecv,
                TransactionLine.rate as TransactionLineRate,
                TransactionLine.rateamount as TransactionLineRateAmount,
                TransactionLine.uniquekey as TransactionLineUniqueKey
            FROM transaction
            INNER JOIN TransactionLine
                ON TransactionLine.Transaction = transaction.ID
            LEFT JOIN entity
                ON transaction.entity = entity.id
            INNER JOIN customer
                ON entity.customer = customer.id
            LEFT JOIN transactionBillingAddress
                ON transaction.billingaddress = transactionBillingAddress.nkey
            LEFT JOIN Item
                ON TransactionLine.item = Item.id
            INNER JOIN tranPromotion
                ON transaction.id = tranPromotion.transaction
            INNER JOIN promotionCode
                ON tranPromotion.promocode = promotionCode.id
            INNER JOIN CUSTOMLIST_NLI_STATUS
                ON transaction.custbody_nli_status = CUSTOMLIST_NLI_STATUS.id
            INNER JOIN CUSTOMLIST_SOS_TYPE
                ON transaction.custbody_shared_order_type = CUSTOMLIST_SOS_TYPE.id
            WHERE transaction.Type = 'SalesOrd'
                AND (TransactionLine.itemtype IN ('Discount', 'ShipItem', 'TaxItem', 'Assembly', 'NonInvtPart'))
                AND transaction.trandate >= TO_DATE('".Carbon::parse($fromDate)->format('m/d/Y')."', 'mm/dd/yyyy')
                AND transaction.custbody_division_domain = '".Helpers::getDomain($config['netsuite_store_base_url'])."'
                AND transaction.id >= " . (isset($lastChanneledOrder['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledOrder['platformId'] : 0);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $query .= " AND transaction.$key = '$value'";
            }
        }
        $query .= " ORDER BY transaction.id ASC";
        $netsuiteClient->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function($orders) {
                self::process(NetSuiteConvert::orders($orders));
            }
        );
        return new Response(json_encode(['Orders retrieved']));
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
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws ORMException
     */
    public static function process(ArrayCollection $channeledCollection): Response
    {
        try {
            $manager = Helpers::getManager();
            $repos = self::initializeRepositories(manager: $manager);

            foreach ($channeledCollection as $order) {
                self::processSingleOrder(
                    order: $order,
                    repos: $repos,
                    manager: $manager
                );
            }

            return new Response(content: json_encode(value: ['Orders processed']));
        } catch (Exception $e) {
            return new Response(
                content: json_encode(value: ['error' => $e->getMessage()]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
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
            'order' => $manager->getRepository(entityName: Order::class),
            'channeledOrder' => $manager->getRepository(entityName: ChanneledOrder::class),
            'channeledDiscount' => $manager->getRepository(entityName: ChanneledDiscount::class),
            'channeledProduct' => $manager->getRepository(entityName: ChanneledProduct::class),
            'channeledProductVariant' => $manager->getRepository(entityName: ChanneledProductVariant::class),
            'channeledCustomer' => $manager->getRepository(entityName: ChanneledCustomer::class),
        ];
    }

    /**
     * @param object $order
     * @param array $repos
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processSingleOrder(object $order, array $repos, EntityManager $manager): void
    {
        $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());

        // Process main order
        $orderEntity = self::getOrCreateOrder(
            order: $order,
            repository: $repos['order']
        );

        // Process channeled order
        $channeledOrderEntity = self::getOrCreateChanneledOrder(
            order: $order,
            repository: $repos['channeledOrder']
        );

        // Process discounts
        $discountCodes = self::processOrderDiscounts(
            order: $order,
            channeledOrderEntity: $channeledOrderEntity,
            repository: $repos['channeledDiscount'],
            manager: $manager
        );

        // Process line items
        $lineItemIds = self::processOrderLineItems(
            order: $order,
            channeledOrderEntity: $channeledOrderEntity,
            repos: $repos,
            manager: $manager
        );

        // Process customer
        $channeledCustomerEntity = self::getOrCreateChanneledCustomer(
            order: $order,
            repository: $repos['channeledCustomer']
        );

        // Finalize relationships
        self::finalizeOrderRelationships(
            orderEntity: $orderEntity,
            channeledOrderEntity: $channeledOrderEntity,
            channeledCustomerEntity: $channeledCustomerEntity,
            manager: $manager
        );

        // Invalidate caches for all affected entities
        $entities = [
            'Order' => $orderEntity->getOrderId(),
            'ChanneledOrder' => $channeledOrderEntity->getPlatformId(),
            'ChanneledCustomer' => $channeledCustomerEntity?->getPlatformId(),
            'ChanneledDiscount' => $discountCodes,
            'ChanneledProduct' => $lineItemIds['productIds'],
            'ChanneledProductVariant' => $lineItemIds['variantIds'],
        ];
        $cacheService->invalidateMultipleEntities(
            entities: array_filter($entities, fn($value) => !empty($value)),
            channel: $order->channel
        );
    }

    /**
     * @param object $order
     * @param EntityRepository $repository
     * @return Order
     */
    private static function getOrCreateOrder(object $order, EntityRepository $repository): Order
    {
        return $repository->existsByOrderId(orderId: $order->platformId)
            ? $repository->getByOrderId(orderId: $order->platformId)
            : $repository->create(
                data: (object) ['orderId' => $order->platformId],
                returnEntity: true
            );
    }

    /**
     * @param object $order
     * @param EntityRepository $repository
     * @return ChanneledOrder
     */
    private static function getOrCreateChanneledOrder(object $order, EntityRepository $repository): ChanneledOrder
    {
        if (!$repository->existsByPlatformId(platformId: $order->platformId, channel: $order->channel)) {
            return $repository->create(
                data: $order,
                returnEntity: true
            );
        }

        $entity = $repository->getByPlatformId(platformId: $order->platformId, channel: $order->channel);

        if ($order->channel === Channels::netsuite->value) {
            $data = $entity->getData();
            $data['line_items'] = isset($data['line_items']) && count($data['line_items'])
                ? [...$data['line_items'], ...$order->data['line_items']]
                : $order->data['line_items'];

            $entity->addData(data: ['line_items' => Helpers::multiDimensionalArrayUnique($data['line_items'])]);
        }

        return $entity;
    }

    /**
     * @param object $order
     * @param ChanneledOrder $channeledOrderEntity
     * @param EntityRepository $repository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processOrderDiscounts(
        object $order,
        ChanneledOrder $channeledOrderEntity,
        EntityRepository $repository,
        EntityManager $manager
    ): array {
        $discountCodes = [];
        foreach ($order->discountCodes as $discountCode) {
            $discountEntity = $repository->existsByCode(code: $discountCode, channel: $order->channel)
                ? $repository->getByCode(code: $discountCode, channel: $order->channel)
                : $repository->create(
                    data: (object) [
                        'code' => $discountCode,
                        'channel' => $order->channel,
                        'platformId' => 0,
                        'data' => [],
                    ],
                    returnEntity: true
                );

            $channeledOrderEntity->addChanneledDiscount(channeledDiscount: $discountEntity);
            $manager->persist(entity: $discountEntity);
            $manager->persist(entity: $channeledOrderEntity);
            $manager->flush();

            $discountCodes[] = $discountCode;
        }
        return $discountCodes;
    }

    /**
     * @param object $order
     * @param ChanneledOrder $channeledOrderEntity
     * @param array $repos
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processOrderLineItems(
        object $order,
        ChanneledOrder $channeledOrderEntity,
        array $repos,
        EntityManager $manager
    ): array {
        $productIds = [];
        $variantIds = [];

        foreach ($order->lineItems as $lineItem) {
            $productEntity = self::getOrCreateChanneledProduct(
                lineItem: $lineItem,
                channel: $order->channel,
                repos: $repos
            );
            $variantEntity = self::getOrCreateChanneledProductVariant(
                lineItem: $lineItem,
                channel: $order->channel,
                repos: $repos
            );

            if ($productEntity && $variantEntity) {
                $productEntity->addChanneledProductVariant(channeledProductVariant: $variantEntity);
                $manager->persist(entity: $productEntity);
            }

            $manager->persist(entity: $channeledOrderEntity);
            $manager->flush();

            if ($productEntity && !empty($lineItem['product_id'])) {
                $productIds[] = $lineItem['product_id'];
            }
            if ($variantEntity && !empty($lineItem['variant_id'])) {
                $variantIds[] = $lineItem['variant_id'];
            }
        }

        return [
            'productIds' => array_unique($productIds),
            'variantIds' => array_unique($variantIds),
        ];
    }

    /**
     * @param array $lineItem
     * @param string $channel
     * @param array $repos
     * @return ChanneledProduct|null
     */
    private static function getOrCreateChanneledProduct(array $lineItem, string $channel, array $repos): ?ChanneledProduct
    {
        if (empty($lineItem['product_id'])) {
            return null;
        }

        $entity = $repos['channeledProduct']->existsByPlatformId(platformId: $lineItem['product_id'], channel: $channel)
            ? $repos['channeledProduct']->getByPlatformId(platformId: $lineItem['product_id'], channel: $channel)
            : $repos['channeledProduct']->create(
                data: (object) [
                    'channel' => $channel,
                    'platformId' => $lineItem['product_id'],
                    'data' => [],
                ],
                returnEntity: true
            );

        $repos['channeledOrder']->addChanneledProduct(channeledProduct: $entity);
        return $entity;
    }

    /**
     * @param array $lineItem
     * @param string $channel
     * @param array $repos
     * @return ChanneledProductVariant|null
     */
    private static function getOrCreateChanneledProductVariant(array $lineItem, string $channel, array $repos): ?ChanneledProductVariant
    {
        if (empty($lineItem['variant_id'])) {
            return null;
        }

        return $repos['channeledProductVariant']->existsByPlatformId(platformId: $lineItem['variant_id'], channel: $channel)
            ? $repos['channeledProductVariant']->getByPlatformId(platformId: $lineItem['variant_id'], channel: $channel)
            : $repos['channeledProductVariant']->create(
                data: (object) [
                    'channel' => $channel,
                    'platformId' => $lineItem['variant_id'],
                    'data' => [],
                ],
                returnEntity: true
            );
    }

    /**
     * @param object $order
     * @param EntityRepository $repository
     * @return ChanneledCustomer|null
     */
    private static function getOrCreateChanneledCustomer(object $order, EntityRepository $repository): ?ChanneledCustomer
    {
        if (isset($order->customer->id) && $order->customer->id) {
            return $repository->existsByPlatformId(platformId: $order->customer->id, channel: $order->channel)
                ? $repository->getByPlatformId(platformId: $order->customer->id, channel: $order->channel)
                : $repository->create(
                    data: (object) [
                        'channel' => $order->channel,
                        'platformId' => $order->customer->id,
                        'email' => $order->customer->email ?? '',
                        'data' => [],
                    ],
                    returnEntity: true
                );
        }

        if (isset($order->customer->email) && $order->customer->email) {
            return $repository->existsByEmail(email: $order->customer->email, channel: $order->channel)
                ? $repository->getByEmail(email: $order->customer->email, channel: $order->channel)
                : $repository->create(
                    data: (object) [
                        'channel' => $order->channel,
                        'platformId' => $order->customer->id ?? '',
                        'email' => $order->customer->email,
                        'data' => [],
                    ],
                    returnEntity: true
                );
        }

        return null;
    }

    /**
     * @param Order $orderEntity
     * @param ChanneledOrder $channeledOrderEntity
     * @param ChanneledCustomer|null $channeledCustomerEntity
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function finalizeOrderRelationships(
        Order $orderEntity,
        ChanneledOrder $channeledOrderEntity,
        ?ChanneledCustomer $channeledCustomerEntity,
        EntityManager $manager
    ): void {
        $orderEntity->addChanneledOrder(channeledOrder: $channeledOrderEntity);
        $manager->persist(entity: $orderEntity);

        if ($channeledCustomerEntity) {
            $channeledCustomerEntity->addChanneledOrder(channeledOrder: $channeledOrderEntity);
            $manager->persist(entity: $channeledCustomerEntity);
        }

        $manager->persist(entity: $channeledOrderEntity);
        $manager->flush();
    }
}