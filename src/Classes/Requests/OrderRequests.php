<?php

declare(strict_types=1);

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\Conversions\NetSuiteConvert;
use Anibalealvarezs\NetSuiteApi\NetSuiteApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Entities\Analytics\Channeled\ChanneledOrder;
use Enums\Channel;
use Repositories\Channeled\ChanneledOrderRepository;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class OrderRequests implements RequestInterface
{
    /**
     * @return Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::klaviyo,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
        ];
    }

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
    public static function getListFromShopify(
        ?string $processedAtMin = null,
        ?string $processedAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('shopify', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'processedAtMin' => $processedAtMin,
                    'processedAtMax' => $processedAtMax,
                    'fields' => $fields,
                    'filters' => $filters,
                ]);
            } catch (\Exception $e) {}
        }
        
        return new Response(json_encode(['Orders retrieved']));
    }

    /**
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromKlaviyo(?array $fields = null, ?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
                return (new \Core\Services\SyncService())->execute('bigcommerce', $createdAtMin, $createdAtMax, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                ]);
            } catch (\Exception $e) {}
        }

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
    public static function getListFromNetsuite(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('netsuite', $createdAtMin, $createdAtMax, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'orders'
                ]);
            } catch (\Exception $e) {}
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
        /** @var ChanneledOrderRepository $channeledOrderRepository */
        $channeledOrderRepository = $manager->getRepository(entityName: ChanneledOrder::class);
        $lastChanneledOrder = $channeledOrderRepository->getLastByPlatformId(channel: Channel::netsuite->value);

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
                AND transaction.trandate >= TO_DATE('".($createdAtMin ? Carbon::parse($createdAtMin)->format('m/d/Y') : '01/01/1989')."', 'mm/dd/yyyy')
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
            callback: function ($orders) use ($jobId) {
                Helpers::checkJobStatus($jobId);
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
    public static function getListFromAmazon(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('amazon', $createdAtMin, $createdAtMax, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                ]);
            } catch (\Exception $e) {}
        }

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

            $result = \Classes\OrderProcessor::processOrders($channeledCollection, $manager);

            if (!empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $entities = [
                    'Order' => $result['orders'],
                    'ChanneledOrder' => $result['channeledOrders'],
                    'ChanneledCustomer' => $result['channeledCustomers'],
                    'ChanneledDiscount' => $result['discounts'],
                    'ChanneledProduct' => $result['channeledProducts'],
                    'ChanneledProductVariant' => $result['channeledVariants'],
                ];

                $channelName = Channel::from(reset($result['channels']))->getName();

                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn ($value) => !empty($value)),
                    channel: $channelName
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
}
