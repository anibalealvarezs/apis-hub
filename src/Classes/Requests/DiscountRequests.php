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
use Entities\Analytics\Discount;
use Entities\Analytics\PriceRule;
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
        $manager = Helpers::getManager();
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $sourcePriceRules = $shopifyClient->getAllPriceRules(
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
        $priceRulesCollection = ShopifyConvert::priceRules($sourcePriceRules['price_rules']);
        $channeledPriceRulesRepository = $manager->getRepository(ChanneledPriceRule::class);
        $channeledDiscountsRepository = $manager->getRepository(ChanneledDiscount::class);
        $discountRepository = $manager->getRepository(Discount::class);
        $priceRuleRepository = $manager->getRepository(PriceRule::class);
        foreach ($priceRulesCollection as $channeledPriceRule) {
            if (!$channeledPriceRulesRepository->getByPlatformIdAndChannel($channeledPriceRule->platformId, $channeledPriceRule->channel)) {
                $priceRuleEntity = $priceRuleRepository->create(
                    data: (object) [
                        'priceRuleId' => $channeledPriceRule->platformId,
                    ],
                    returnEntity: true,
                );
                $channeledPriceRuleEntity = $channeledPriceRulesRepository->create(
                    data: $channeledPriceRule,
                    returnEntity: true,
                );
                $channeledPriceRuleEntity->addPriceRule($priceRuleEntity);
                $manager->persist($priceRuleEntity);
                $manager->flush();
                $sourceDiscountCodes = $shopifyClient->getAllDiscountCodes(
                    priceRuleId: $channeledPriceRule->platformId,
                );
                $discountsCollection = ShopifyConvert::discounts($sourceDiscountCodes['discount_codes']);
                foreach ($discountsCollection as $channeledDiscount) {
                    if (!$discountEntity = $discountRepository->getByCode($channeledDiscount->code)) {
                        $discountEntity = $discountRepository->create(
                            data: (object) ['code' => $channeledDiscount->code,],
                            returnEntity: true,
                        );
                    }
                    if (!$channeledDiscountEntity = $channeledDiscountsRepository->getByCodeAndChannel($channeledDiscount->code, $channeledDiscount->channel)) {
                        $channeledDiscountEntity = $channeledDiscountsRepository->create(
                            data: $channeledDiscount,
                            returnEntity: true,
                        );
                    }
                    if (empty($channeledDiscountEntity->getData())) {
                        $channeledDiscountEntity
                            ->addPlatformId($channeledDiscount->platformId)
                            ->addData($channeledDiscount->data);
                    }
                    $discountEntity->addChanneledDiscount($channeledDiscountEntity);
                    $channeledPriceRuleEntity->addChanneledDiscount($channeledDiscountEntity);
                    $manager->persist($discountEntity);
                    $manager->persist($channeledPriceRuleEntity);
                    $manager->persist($channeledDiscountEntity);
                    $manager->flush();
                }
            }
        }
        return new Response(json_encode($sourcePriceRules));
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
}