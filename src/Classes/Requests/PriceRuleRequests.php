<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\ShopifyApi\Conversions\ShopifyConvert;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Entities\Analytics\Channeled\ChanneledPriceRule;
use Enums\Channel;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Repositories\Channeled\ChanneledPriceRuleRepository;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class PriceRuleRequests implements RequestInterface
{
    /**
     * @param \Enums\Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public static function getList(
        Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): Response {
        $chanEnum = ($channel instanceof Channel) ? $channel : Channel::tryFromName((string)$channel);
        $method = 'getListFrom' . $chanEnum->getCommonName();
        if (method_exists(self::class, $method)) {
            if ($chanEnum === Channel::shopify) {
                return self::getListFromShopify(
                    createdAtMin: $startDate,
                    createdAtMax: $endDate,
                    filters: $filters,
                    resume: $filters->resume ?? true,
                    jobId: $jobId
                );
            }

            return self::$method(
                filters: $filters,
                resume: $filters->resume ?? true,
                jobId: $jobId
            );
        }

        throw new \Exception("Channel " . ($chanEnum?->name ?? (string)$channel) . " not supported for PriceRule entities");
    }

    
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromShopify(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
        /** @var ChanneledPriceRuleRepository $channeledPriceRuleRepository */
        $channeledPriceRuleRepository = $manager->getRepository(entityName: ChanneledPriceRule::class);
        $lastChanneledPriceRule = $channeledPriceRuleRepository->getLastByPlatformId(channel: Channel::shopify->value);

        $shopifyClient->getAllPriceRulesAndProcess(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            endsAtMin: $filters->endsAtMin ?? null,
            endsAtMax: $filters->endsAtMax ?? null,
            sinceId: $filters->sinceId ?? (isset($lastChanneledPriceRule['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledPriceRule['platformId'] : null),
            startsAtMin: $filters->startsAtMin ?? null,
            startsAtMax: $filters->startsAtMax ?? null,
            timesUsed: $filters->timesUsed ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
            callback: function ($priceRules) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(ShopifyConvert::priceRules($priceRules));
            }
        );

        return new Response(json_encode(['Price rules retrieved']));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
        return self::processPriceRules(channeledCollection: $channeledCollection);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws ORMException
     */
    public static function processPriceRules(ArrayCollection $channeledCollection): Response
    {
        try {
            $manager = Helpers::getManager();

            $result = \Classes\PriceRuleProcessor::processPriceRules($channeledCollection, $manager);

            if (! empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $channelName = Channel::from(reset($result['channels']))->getName();

                $discountCodesTotal = [];
                $channeledDiscountCodesTotal = [];

                // Now process Shopify Discount Codes if any
                if ($channelName === Channel::shopify->getName()) {
                    $config = Helpers::getChannelsConfig()['shopify'];
                    $shopifyClient = new ShopifyApi(
                        apiKey: $config['shopify_api_key'],
                        shopName: $config['shopify_shop_name'],
                        version: $config['shopify_last_stable_revision']
                    );

                    foreach ($result['cprDbIds'] as $platformId => $dbId) {
                        $shopifyClient->getAllDiscountCodesAndProcess(
                            priceRuleId: $platformId,
                            callback: function ($discountCodesResponse) use ($platformId, $dbId, $manager, &$discountCodesTotal, &$channeledDiscountCodesTotal) {
                                $discResult = \Classes\PriceRuleProcessor::processDiscounts(
                                    channeledCollection: ShopifyConvert::discounts(discounts: $discountCodesResponse),
                                    priceRulePlatformId: $platformId,
                                    channeledPriceRuleDbId: $dbId,
                                    manager: $manager
                                );

                                if (isset($discResult['discountCodes'])) {
                                    $discountCodesTotal = array_merge($discountCodesTotal, $discResult['discountCodes']);
                                }
                                if (isset($discResult['channeledDiscountCodes'])) {
                                    $channeledDiscountCodesTotal = array_merge($channeledDiscountCodesTotal, $discResult['channeledDiscountCodes']);
                                }
                            }
                        );
                    }
                }

                $entities = [
                    'PriceRule' => $result['priceRules'],
                    'ChanneledPriceRule' => $result['channeledPriceRules'],
                    'Discount' => array_unique($discountCodesTotal),
                    'ChanneledDiscount' => array_unique($channeledDiscountCodesTotal),
                ];

                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn ($value) => ! empty($value)),
                    channel: $channelName
                );
            }

            return new Response(content: json_encode(value: ['Discounts processed']));
        } catch (Exception | GuzzleException | \Throwable $e) {
            return new Response(
                content: json_encode(value: ['error' => $e->getMessage()]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
