<?php

namespace Classes\Requests;

use Classes\Conversions\ShopifyConvert;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ObjectRepository;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channeled\ChanneledPriceRule;
use Entities\Analytics\Discount;
use Entities\Analytics\PriceRule;
use Enums\Channels;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class PriceRuleRequests implements RequestInterface
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param object|null $filters
     * @return Response
     * @throws Exception
     * @throws GuzzleException
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

        $manager = Helpers::getManager();
        $channeledPriceRuleRepository = $manager->getRepository(entityName: ChanneledPriceRule::class);
        $lastChanneledPriceRule = $channeledPriceRuleRepository->getLastByPlatformId(channel: Channels::shopify->value);

        $shopifyClient->getAllPriceRulesAndProcess(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            endsAtMin: $filters->endsAtMin ?? null,
            endsAtMax: $filters->endsAtMax ?? null,
            sinceId: $filters->sinceId ?? $lastChanneledPriceRule ?: null,
            startsAtMin: $filters->startsAtMin ?? null,
            startsAtMax: $filters->startsAtMax ?? null,
            timesUsed: $filters->timesUsed ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
            callback: function($priceRules) {
                self::process(ShopifyConvert::priceRules($priceRules));
            }
        );
        return new Response(json_encode(['Price rules retrieved']));
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
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    static function process(
        ArrayCollection $channeledCollection,
    ): Response
    {
        return self::processPriceRules($channeledCollection);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function processPriceRules(
        ArrayCollection $channeledCollection,
    ): Response
    {
        $manager = Helpers::getManager();
        $channeledPriceRulesRepository = $manager->getRepository(entityName: ChanneledPriceRule::class);
        $channeledDiscountsRepository = $manager->getRepository(entityName: ChanneledDiscount::class);
        $discountRepository = $manager->getRepository(entityName: Discount::class);
        $priceRuleRepository = $manager->getRepository(entityName: PriceRule::class);
        foreach ($channeledCollection as $channeledPriceRule) {
            if (!$channeledPriceRulesRepository->existsByPlatformId($channeledPriceRule->platformId, $channeledPriceRule->channel)) {
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
                $config = Helpers::getChannelsConfig()['shopify'];
                $shopifyClient = new ShopifyApi(
                    apiKey: $config['shopify_api_key'],
                    shopName: $config['shopify_shop_name'],
                    version: $config['shopify_last_stable_revision'],
                );
                $shopifyClient->getAllDiscountCodesAndProcess(
                    priceRuleId: $channeledPriceRule->platformId,
                    callback: function($discountCodes) use ($manager, $channeledPriceRuleEntity, $discountRepository, $channeledDiscountsRepository) {
                        self::processDiscounts(
                            channeledCollection: ShopifyConvert::discounts($discountCodes),
                            channeledPriceRuleEntity: $channeledPriceRuleEntity,
                            discountRepository: $discountRepository,
                            channeledDiscountsRepository: $channeledDiscountsRepository,
                        );
                    }
                );
            }
        }
        return new Response(json_encode(['Discounts processed']));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param ChanneledPriceRule $channeledPriceRuleEntity
     * @param EntityRepository|ObjectRepository $discountRepository
     * @param EntityRepository|ObjectRepository $channeledDiscountsRepository
     * @return void
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function processDiscounts(
        ArrayCollection $channeledCollection,
        ChanneledPriceRule $channeledPriceRuleEntity,
        EntityRepository|ObjectRepository $discountRepository,
        EntityRepository|ObjectRepository $channeledDiscountsRepository,
    ): void
    {
        $manager = Helpers::getManager();
        foreach ($channeledCollection as $channeledDiscount) {
            if (!$discountRepository->existsByCode($channeledDiscount->code)) {
                $discountEntity = $discountRepository->create(
                    data: (object) ['code' => $channeledDiscount->code,],
                    returnEntity: true,
                );
            } else {
                $discountEntity = $discountRepository->getByCode($channeledDiscount->code);
            }
            if (!$channeledDiscountsRepository->existsByCode($channeledDiscount->code, $channeledDiscount->channel)) {
                $channeledDiscountEntity = $channeledDiscountsRepository->create(
                    data: $channeledDiscount,
                    returnEntity: true,
                );
            } else {
                $channeledDiscountEntity = $channeledDiscountsRepository->getByCode($channeledDiscount->code, $channeledDiscount->channel);
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