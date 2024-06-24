<?php

namespace Classes\Requests;

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
     * @return Response
     * @throws GuzzleException
     */
    public static function getListFromShopify(string $processedAtMin = null, string $processedAtMax = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $shopifyClient->getAllOrders(
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
            callback: function($orders) {
                self::process(ShopifyConvert::orders($orders));
            }
        );
        return new Response(json_encode(['Orders retrieved']));
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
            if (!$channeledOrderRepository->existsByPlatformIdAndChannel($order->platformId, $order->channel)) {
                $channeledOrderEntity = $channeledOrderRepository->create(
                    data: $order,
                    returnEntity: true,
                );
            } else {
                $channeledOrderEntity = $channeledOrderRepository->getByPlatformIdAndChannel($order->platformId, $order->channel);
            }
            foreach($order->discountCodes as $discountCode) {
                if (!$channeledDiscountRepository->existsByCodeAndChannel($discountCode, $order->channel)) {
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
                    $channeledDiscountEntity = $channeledDiscountRepository->getByCodeAndChannel($discountCode, $order->channel);
                }
                $channeledOrderEntity->addChanneledDiscount($channeledDiscountEntity);
                $manager->persist($channeledDiscountEntity);
                $manager->persist($channeledOrderEntity);
                $manager->flush();
            }
            foreach($order->lineItems as $lineItem) {
                if ($lineItem['product_id']) {
                    if (!$channeledProductRepository->existsByPlatformIdAndChannel($lineItem['product_id'], $order->channel)) {
                        $channeledProductEntity = $channeledProductRepository->create(
                            data: (object) [
                                'channel' => $order->channel,
                                'platformId' => $lineItem['product_id'],
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductEntity = $channeledProductRepository->getByPlatformIdAndChannel($lineItem['product_id'], $order->channel);
                    }
                    $channeledOrderEntity->addChanneledProduct($channeledProductEntity);
                }
                if ($lineItem['variant_id']) {
                    if (!$channeledProductVariantRepository->existsByPlatformIdAndChannel($lineItem['variant_id'], $order->channel)) {
                        $channeledProductVariantEntity = $channeledProductVariantRepository->create(
                            data: (object) [
                                'channel' => $order->channel,
                                'platformId' => $lineItem['variant_id'],
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductVariantEntity = $channeledProductVariantRepository->getByPlatformIdAndChannel($lineItem['variant_id'], $order->channel);
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
            $customerId = isset($order->customer->id) && $order->customer->id ? $order->customer->id : 'fake-'.$order->platformId;
            $email = isset($order->customer->email) && $order->customer->email ? $order->customer->email : $customerId . '@' . $order->channel;
            if (!$channeledCustomerRepository->existsByEmailAndChannel($email, $order->channel)) {
                $channeledCustomerEntity = $channeledCustomerRepository->create(
                    data: (object) [
                        'channel' => $order->channel,
                        'platformId' => $customerId,
                        'email' => $email,
                        'data' => [],
                    ],
                    returnEntity: true,
                );
            } else {
                $channeledCustomerEntity = $channeledCustomerRepository->getByEmailAndChannel($email, $order->channel);
            }
            $channeledCustomerEntity->addChanneledOrder($channeledOrderEntity);
            $orderEntity->addChanneledOrder($channeledOrderEntity);
            $manager->persist($orderEntity);
            $manager->persist($channeledCustomerEntity);
            $manager->persist($channeledOrderEntity);
            $manager->flush();
        }

        return new Response(json_encode(['Orders processed']));
    }
}