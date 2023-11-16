<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channeled\ChanneledPriceRule;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

class DiscountRequests
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param object|null $filters
     * @return Response
     * @throws GuzzleException
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $priceRules = $shopifyClient->getAllPriceRules(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            endsAtMin: $filters->endsAtMin ?? null,
            endsAtMax: $filters->endsAtMax ?? null,
            sinceId: $filters->sinceId ?? null,
            startsAtMin: $filters->startsAtMin ?? null,
            startsAtMax: $filters->startsAtMax ?? null,
            timesUsed: $filters->timesUsed ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
        $priceRulesCollection = ShopifyConvert::priceRules($priceRules['price_rules']);
        $priceRulesRepository = Helpers::getManager()->getRepository(ChanneledPriceRule::class);
        foreach ($priceRulesCollection as $priceRule) {
            if (!$priceRulesRepository->getByPlatformIdAndChannel($priceRule->platformId, $priceRule->channel)) {
                $priceRulesRepository->create($priceRule);
                $priceRuleEntity = $priceRulesRepository->getByPlatformIdAndChannel($priceRule->platformId, $priceRule->channel);
                $discountCodes = $shopifyClient->getAllDiscountCodes(
                    priceRuleId: $priceRuleEntity->getPlatformId(),
                );
                $discountsCollection = ShopifyConvert::discounts($discountCodes['discount_codes']);
                $discountsRepository = Helpers::getManager()->getRepository(ChanneledDiscount::class);
                $discountEntitiesCollection = new ArrayCollection();
                foreach ($discountsCollection as $discount) {
                    if (!$discountEntity = $discountsRepository->getByPlatformIdAndChannel($discount->platformId, $discount->channel)) {
                        // $discountsRepository->create($discount);
                        $discountEntity = new ChanneledDiscount();
                        $discountEntity->addPlatformId($discount->platformId);
                        $discountEntity->addChannel($discount->channel);
                        $discountEntity->addData($discount->data);
                        $discountEntity->addChanneledPriceRule($priceRuleEntity);
                    }
                    $discountEntitiesCollection->add($discountEntity);
                }
                $priceRuleEntity->addChanneledDiscounts($discountEntitiesCollection);
                Helpers::getManager()->persist($priceRuleEntity);
                Helpers::getManager()->flush();
            }
        }
        return new Response(json_encode($priceRules));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        //
        return [];
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        //
        return [];
    }
}