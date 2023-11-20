<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channeled\ChanneledOrder;
use Entities\Analytics\Channeled\ChanneledProduct;
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
        $ordersRepository = Helpers::getManager()->getRepository(ChanneledOrder::class);
        foreach ($ordersCollection as $order) {
            if (!$ordersRepository->getByPlatformIdAndChannel($order->platformId, $order->channel)) {
                $ordersRepository->create($order);
                $orderEntity = $ordersRepository->getByPlatformIdAndChannel($order->platformId, $order->channel);
                $discountsRepository = Helpers::getManager()->getRepository(ChanneledDiscount::class);
                $discountEntitiesCollection = new ArrayCollection();
                $priceRuleEntitiesCollection = new ArrayCollection();
                foreach($order->discountCodes as $discount) {
                    if ($discountEntity = $discountsRepository->getByPlatformIdAndChannel($discount, $order->channel)) {
                        $discountEntitiesCollection->add($discountEntity);
                        if (!$priceRuleEntitiesCollection->contains($discountEntity->getChanneledPriceRule())) {
                            $priceRuleEntitiesCollection->add($discountEntity->getChanneledPriceRule());
                        }
                    }
                }
                $productsRepository = Helpers::getManager()->getRepository(ChanneledProduct::class);
                $productEntitiesCollection = new ArrayCollection();
                foreach($order->lineItems as $lineItem) {
                    if ($productEntity = $productsRepository->getByPlatformIdAndChannel($lineItem['product_id'], $order->channel)) {
                        $productEntitiesCollection->add($productEntity);
                    }
                }
                $orderEntity->addChanneledDiscounts($discountEntitiesCollection);
                $orderEntity->addChanneledPriceRules($priceRuleEntitiesCollection);
                $orderEntity->addChanneledProducts($productEntitiesCollection);
                Helpers::getManager()->persist($orderEntity);
                Helpers::getManager()->flush();
            }
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