<?php

declare(strict_types=1);

namespace Classes\Requests;

use Classes\Conversions\ShopifyConvert;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channeled\ChanneledPriceRule;
use Entities\Analytics\Discount;
use Entities\Analytics\PriceRule;
use Enums\Channel;
use Repositories\BaseRepository;
use Repositories\Channeled\ChanneledDiscountRepository;
use Repositories\Channeled\ChanneledPriceRuleRepository;
use Repositories\DiscountRepository;
use Repositories\PriceRuleRepository;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class PriceRuleRequests implements RequestInterface
{
    /**
     * @return \Enums\Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
        ];
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
        string|bool $resume = true, ?int $jobId = null): Response {
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
            callback: function($priceRules) use ($jobId) {
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
            $repos = self::initializeRepositories(manager: $manager);

            foreach ($channeledCollection as $channeledPriceRule) {
                self::processSinglePriceRule(
                    channeledPriceRule: $channeledPriceRule,
                    repos: $repos,
                    manager: $manager
                );
            }

            return new Response(content: json_encode(value: ['Discounts processed']));
        } catch (Exception | GuzzleException $e) {
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
            'channeledPriceRule' => $manager->getRepository(entityName: ChanneledPriceRule::class),
            'channeledDiscount' => $manager->getRepository(entityName: ChanneledDiscount::class),
            'discount' => $manager->getRepository(entityName: Discount::class),
            'priceRule' => $manager->getRepository(entityName: PriceRule::class),
        ];
    }

    /**
     * @param object $channeledPriceRule
     * @param array $repos
     * @param EntityManager $manager
     * @return void
     * @throws Exception
     * @throws GuzzleException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processSinglePriceRule(object $channeledPriceRule, array $repos, EntityManager $manager): void
    {
        if ($repos['channeledPriceRule']->existsByPlatformId(platformId: $channeledPriceRule->platformId, channel: $channeledPriceRule->channel)) {
            return;
        }

        $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());

        $priceRuleEntity = self::getOrCreatePriceRule(
            priceRule: $channeledPriceRule,
            repository: $repos['priceRule']
        );

        $channeledPriceRuleEntity = self::getOrCreateChanneledPriceRule(
            channeledPriceRule: $channeledPriceRule,
            repository: $repos['channeledPriceRule']
        );

        $channeledPriceRuleEntity->addPriceRule(priceRule: $priceRuleEntity);
        $manager->persist(entity: $priceRuleEntity);
        $manager->persist(entity: $channeledPriceRuleEntity);
        $manager->flush();

        $discountCodes = self::processShopifyDiscountCodes(
            channeledPriceRule: $channeledPriceRule,
            channeledPriceRuleEntity: $channeledPriceRuleEntity,
            discountRepository: $repos['discount'],
            channeledDiscountRepository: $repos['channeledDiscount'],
            manager: $manager
        );

        // Invalidate caches for all affected entities
        $entities = [
            'PriceRule' => $priceRuleEntity->getPriceRuleId(),
            'ChanneledPriceRule' => $channeledPriceRuleEntity->getPlatformId(),
            'Discount' => $discountCodes['discountCodes'],
            'ChanneledDiscount' => $discountCodes['channeledDiscountCodes'],
        ];
        $cacheService->invalidateMultipleEntities(
            entities: array_filter($entities, fn($value) => !empty($value)),
            channel: $channeledPriceRule->channel
        );
    }

    /**
     * @param object $priceRule
     * @param PriceRuleRepository $repository
     * @return PriceRule
     */
    private static function getOrCreatePriceRule(object $priceRule, PriceRuleRepository $repository): PriceRule
    {
        /** @var PriceRule $entity */
        $entity = $repository->create(
            data: (object) ['priceRuleId' => $priceRule->platformId],
            returnEntity: true
        );

        return $entity;
    }

    /**
     * @param object $channeledPriceRule
     * @param ChanneledPriceRuleRepository $repository
     * @return ChanneledPriceRule
     */
    private static function getOrCreateChanneledPriceRule(object $channeledPriceRule, ChanneledPriceRuleRepository $repository): ChanneledPriceRule
    {
        /** @var ChanneledPriceRule $entity */
        $entity = $repository->create(
            data: $channeledPriceRule,
            returnEntity: true
        );

        return $entity;
    }

    /**
     * @param object $channeledPriceRule
     * @param ChanneledPriceRule $channeledPriceRuleEntity
     * @param DiscountRepository $discountRepository
     * @param ChanneledDiscountRepository $channeledDiscountRepository
     * @param EntityManager $manager
     * @return array
     * @throws GuzzleException
     */
    private static function processShopifyDiscountCodes(
        object $channeledPriceRule,
        ChanneledPriceRule $channeledPriceRuleEntity,
        DiscountRepository $discountRepository,
        ChanneledDiscountRepository $channeledDiscountRepository,
        EntityManager $manager
    ): array {
        if ($channeledPriceRule->channel !== Channel::shopify->value) {
            return ['discountCodes' => [], 'channeledDiscountCodes' => []];
        }

        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision']
        );

        $discountCodes = [];
        $channeledDiscountCodes = [];

        $shopifyClient->getAllDiscountCodesAndProcess(
            priceRuleId: $channeledPriceRule->platformId,
            callback: function ($discountCodesResponse) use ($channeledPriceRuleEntity, $discountRepository, $channeledDiscountRepository, $manager, &$discountCodes, &$channeledDiscountCodes) {
                $result = self::processDiscounts(
                    channeledCollection: ShopifyConvert::discounts(discounts: $discountCodesResponse),
                    channeledPriceRuleEntity: $channeledPriceRuleEntity,
                    discountRepository: $discountRepository,
                    channeledDiscountRepository: $channeledDiscountRepository,
                    manager: $manager
                );
                $discountCodes = array_merge($discountCodes, $result['discountCodes']);
                $channeledDiscountCodes = array_merge($channeledDiscountCodes, $result['channeledDiscountCodes']);
            }
        );

        return [
            'discountCodes' => array_unique($discountCodes),
            'channeledDiscountCodes' => array_unique($channeledDiscountCodes),
        ];
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param ChanneledPriceRule $channeledPriceRuleEntity
     * @param DiscountRepository $discountRepository
     * @param ChanneledDiscountRepository $channeledDiscountRepository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processDiscounts(
        ArrayCollection $channeledCollection,
        ChanneledPriceRule $channeledPriceRuleEntity,
        DiscountRepository $discountRepository,
        ChanneledDiscountRepository $channeledDiscountRepository,
        EntityManager $manager
    ): array {
        $discountCodes = [];
        $channeledDiscountCodes = [];

        foreach ($channeledCollection as $channeledDiscount) {
            $discountEntity = self::getOrCreateDiscount(
                channeledDiscount: $channeledDiscount,
                repository: $discountRepository
            );

            $channeledDiscountEntity = self::getOrCreateChanneledDiscount(
                channeledDiscount: $channeledDiscount,
                repository: $channeledDiscountRepository
            );

            self::updateChanneledDiscountData(
                channeledDiscount: $channeledDiscount,
                channeledDiscountEntity: $channeledDiscountEntity
            );

            $discountEntity->addChanneledDiscount(channeledDiscount: $channeledDiscountEntity);
            $channeledPriceRuleEntity->addChanneledDiscount(channeledDiscount: $channeledDiscountEntity);

            $manager->persist(entity: $discountEntity);
            $manager->persist(entity: $channeledPriceRuleEntity);
            $manager->persist(entity: $channeledDiscountEntity);
            $manager->flush();

            $discountCodes[] = $discountEntity->getCode();
            $channeledDiscountCodes[] = $channeledDiscountEntity->getCode();
        }

        return [
            'discountCodes' => array_unique($discountCodes),
            'channeledDiscountCodes' => array_unique($channeledDiscountCodes),
        ];
    }

    /**
     * @param object $channeledDiscount
     * @param DiscountRepository $repository
     * @return Discount
     */
    private static function getOrCreateDiscount(object $channeledDiscount, DiscountRepository $repository): Discount
    {
        /** @var Discount $entity */
        $entity = $repository->getByCode(code: $channeledDiscount->code)
            ?? $repository->create(
                data: (object) ['code' => $channeledDiscount->code],
                returnEntity: true
            );

        return $entity;
    }

    /**
     * @param object $channeledDiscount
     * @param ChanneledDiscountRepository $repository
     * @return ChanneledDiscount
     */
    private static function getOrCreateChanneledDiscount(object $channeledDiscount, ChanneledDiscountRepository $repository): ChanneledDiscount
    {
        /** @var ChanneledDiscount $entity */
        $entity = $repository->getByCode(code: $channeledDiscount->code, channel: $channeledDiscount->channel)
            ?? $repository->create(
                data: $channeledDiscount,
                returnEntity: true
            );

        return $entity;
    }

    /**
     * @param object $channeledDiscount
     * @param ChanneledDiscount $channeledDiscountEntity
     * @return void
     */
    private static function updateChanneledDiscountData(object $channeledDiscount, ChanneledDiscount $channeledDiscountEntity): void
    {
        if (empty($channeledDiscountEntity->getData())) {
            $channeledDiscountEntity
                ->addPlatformId(platformId: $channeledDiscount->platformId)
                ->addData(data: $channeledDiscount->data);
        }
    }
}
