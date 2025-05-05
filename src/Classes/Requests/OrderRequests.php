<?php

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
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
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    static function process(ArrayCollection $channeledCollection): Response
    {
        $manager = Helpers::getManager();
        $channeledOrderRepository = $manager->getRepository(entityName: ChanneledOrder::class);
        $channeledDiscountRepository = $manager->getRepository(entityName: ChanneledDiscount::class);
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $channeledProductVariantRepository = $manager->getRepository(entityName: ChanneledProductVariant::class);
        $channeledCustomerRepository = $manager->getRepository(entityName: ChanneledCustomer::class);
        $orderRepository = $manager->getRepository(entityName: Order::class);

        foreach ($channeledCollection as $order) {
            if (!$orderRepository->existsByOrderId($order->platformId)) {
                $orderEntity = $orderRepository->create(
                    data: (object) [
                        'orderId' => $order->platformId,
                    ],
                    returnEntity: true,
                );
            } else {
                $orderEntity = $orderRepository->getByOrderId($order->platformId);
            }
            if (!$channeledOrderRepository->existsByPlatformId($order->platformId, $order->channel)) {
                $channeledOrderEntity = $channeledOrderRepository->create(
                    data: $order,
                    returnEntity: true,
                );
            } else {
                $channeledOrderEntity = $channeledOrderRepository->getByPlatformId($order->platformId, $order->channel);
                // Special `line_items` merge for NetSuite
                if ($order->channel === Channels::netsuite->value) {
                    $data = $channeledOrderEntity->getData();
                    if (isset($data['line_items']) && count($data['line_items'])) {
                        $data['line_items'] = [...$data['line_items'], ...$order->data['line_items']];
                    } else {
                        $data['line_items'] = $order->data['line_items'];
                    }
                    $data['line_items'] = Helpers::multiDimensionalArrayUnique($data['line_items']);
                    $channeledOrderEntity->addData($data);
                }
                // End of special `line_items` merge for NetSuite
            }
            foreach($order->discountCodes as $discountCode) {
                if (!$channeledDiscountRepository->existsByCode($discountCode, $order->channel)) {
                    $channeledDiscountEntity = $channeledDiscountRepository->create(
                        data: (object) [
                            'code' => $discountCode,
                            'channel' => $order->channel,
                            'platformId' => 0,
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                } else {
                    $channeledDiscountEntity = $channeledDiscountRepository->getByCode($discountCode, $order->channel);
                }
                $channeledOrderEntity->addChanneledDiscount($channeledDiscountEntity);
                $manager->persist($channeledDiscountEntity);
                $manager->persist($channeledOrderEntity);
                $manager->flush();
            }
            foreach($order->lineItems as $lineItem) {
                if ($lineItem['product_id']) {
                    if (!$channeledProductRepository->existsByPlatformId($lineItem['product_id'], $order->channel)) {
                        $channeledProductEntity = $channeledProductRepository->create(
                            data: (object) [
                                'channel' => $order->channel,
                                'platformId' => $lineItem['product_id'],
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductEntity = $channeledProductRepository->getByPlatformId($lineItem['product_id'], $order->channel);
                    }
                    $channeledOrderEntity->addChanneledProduct($channeledProductEntity);
                }
                if ($lineItem['variant_id']) {
                    if (!$channeledProductVariantRepository->existsByPlatformId($lineItem['variant_id'], $order->channel)) {
                        $channeledProductVariantEntity = $channeledProductVariantRepository->create(
                            data: (object) [
                                'channel' => $order->channel,
                                'platformId' => $lineItem['variant_id'],
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductVariantEntity = $channeledProductVariantRepository->getByPlatformId($lineItem['variant_id'], $order->channel);
                    }
                    $channeledOrderEntity->addChanneledProductVariant($channeledProductVariantEntity);
                    $manager->persist($channeledProductVariantEntity);
                    if (isset($channeledProductEntity)) {
                        $channeledProductEntity->addChanneledProductVariant($channeledProductVariantEntity);
                        $manager->persist($channeledProductEntity);
                    }
                }
                $manager->persist($channeledOrderEntity);
                $manager->flush();
            }
            if (isset($order->customer->id) && $order->customer->id) {
                if (!$channeledCustomerRepository->existsByPlatformId($order->customer->id, $order->channel)) {
                    $channeledCustomerEntity = $channeledCustomerRepository->create(
                        data: (object) [
                            'channel' => $order->channel,
                            'platformId' => $order->customer->id,
                            'email' => $order->customer->email ?? '',
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                } else {
                    $channeledCustomerEntity = $channeledCustomerRepository->getByPlatformId($order->customer->id, $order->channel);
                }
            }
            if (!isset($channeledCustomerEntity) && isset($order->customer->email) && $order->customer->email) {
                if (!$channeledCustomerRepository->existsByEmail($order->customer->email, $order->channel)) {
                    $channeledCustomerEntity = $channeledCustomerRepository->create(
                        data: (object) [
                            'channel' => $order->channel,
                            'platformId' => $order->customer->id ?? '',
                            'email' => $order->customer->email,
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                } else {
                    $channeledCustomerEntity = $channeledCustomerRepository->getByEmail($order->customer->email, $order->channel);
                }
            }
            $orderEntity->addChanneledOrder($channeledOrderEntity);
            $manager->persist($orderEntity);
            if (isset($channeledCustomerEntity)) {
                $channeledCustomerEntity->addChanneledOrder($channeledOrderEntity);
                $manager->persist($channeledCustomerEntity);
            }
            $manager->persist($channeledOrderEntity);
            $manager->flush();
            unset($channeledCustomerEntity);
        }

        return new Response(json_encode(['Orders processed']));
    }
}