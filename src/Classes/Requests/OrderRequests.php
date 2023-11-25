<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
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
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

class OrderRequests
{
    /**
     * @param string|null $processedAtMin
     * @param string|null $processedAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromShopify(string $processedAtMin = null, string $processedAtMax = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $manager = Helpers::getManager();
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $orders = $shopifyClient->getAllOrders(
            createdAtMin: $filters->createdAtMin ?? null,
            createdAtMax: $filters->createdAtMax ?? null,
            fields: $fields, // Example: ["id", "processed_at", "total_price", "total_discounts", "discount_codes", "customer", "line_items"]
            financialStatus: $filters->financialStatus ?? null,
            fulfillmentStatus: $filters->fulfillmentStatus ?? null,
            ids: $filters->ids ?? null,
            processedAtMin: $processedAtMin,
            processedAtMax: $processedAtMax,
            sinceId: $filters->sinceId ?? null,
            status: $filters->status ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
        $ordersCollection = ShopifyConvert::orders($orders['orders']);
        $channeledOrderRepository = $manager->getRepository(ChanneledOrder::class);
        $channeledDiscountRepository = $manager->getRepository(ChanneledDiscount::class);
        $channeledProductRepository = $manager->getRepository(ChanneledProduct::class);
        $channeledProductVariantRepository = $manager->getRepository(ChanneledProductVariant::class);
        $channeledCustomerRepository = $manager->getRepository(ChanneledCustomer::class);
        $orderRepository = $manager->getRepository(Order::class);
        foreach ($ordersCollection as $order) {
            if (!$orderEntity = $orderRepository->getByOrderId($order->platformId)) {
                $orderEntity = $orderRepository->create(
                    data: (object) [
                        'orderId' => $order->platformId,
                    ],
                    returnEntity: true,
                );
            }
            if (!$channeledOrderEntity = $channeledOrderRepository->getByPlatformIdAndChannel($order->platformId, $order->channel)) {
                $channeledOrderEntity = $channeledOrderRepository->create(
                    data: $order,
                    returnEntity: true,
                );
            }
            foreach($order->discountCodes as $discountCode) {
                if (!$channeledDiscountEntity = $channeledDiscountRepository->getByCodeAndChannel($discountCode, $order->channel)) {
                    $channeledDiscountEntity = $channeledDiscountRepository->create(
                        data: (object) [
                            'code' => $discountCode,
                            'channel' => $order->channel,
                            'platformId' => 0,
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                }
                $channeledOrderEntity->addChanneledDiscount($channeledDiscountEntity);
                $manager->persist($channeledDiscountEntity);
                $manager->persist($channeledOrderEntity);
                $manager->flush();
            }
            foreach($order->lineItems as $lineItem) {
                if (!$channeledProductEntity = $channeledProductRepository->getByPlatformIdAndChannel($lineItem['product_id'], $order->channel)) {
                    $channeledProductEntity = $channeledProductRepository->create(
                        data: (object) [
                            'channel' => $order->channel,
                            'platformId' => $lineItem['product_id'],
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                }
                if (!$channeledProductVariantEntity = $channeledProductVariantRepository->getByPlatformIdAndChannel($lineItem['variant_id'], $order->channel)) {
                    $channeledProductVariantEntity = $channeledProductVariantRepository->create(
                        data: (object) [
                            'channel' => $order->channel,
                            'platformId' => $lineItem['variant_id'],
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                }
                $channeledOrderEntity->addChanneledProduct($channeledProductEntity);
                $channeledOrderEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $channeledProductEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $manager->persist($channeledProductEntity);
                $manager->persist($channeledProductVariantEntity);
                $manager->persist($channeledOrderEntity);
                $manager->flush();
            }
            if (!$channeledCustomerEntity = $channeledCustomerRepository->getByEmailAndChannel($order->customer->email, $order->channel)) {
                $channeledCustomerEntity = $channeledCustomerRepository->create(
                    data: (object) [
                        'channel' => $order->channel,
                        'platformId' => $order->customer->id,
                        'email' => $order->customer->email,
                        'data' => [],
                    ],
                    returnEntity: true,
                );
            }
            $channeledCustomerEntity->addChanneledOrder($channeledOrderEntity);
            $orderEntity->addChanneledOrder($channeledOrderEntity);
            $manager->persist($orderEntity);
            $manager->persist($channeledCustomerEntity);
            $manager->persist($channeledOrderEntity);
            $manager->flush();
        }
        return new Response(json_encode($orders));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }
}