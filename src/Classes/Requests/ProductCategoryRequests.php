<?php

namespace Classes\Requests;

use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductCategory;
use Entities\Analytics\ProductCategory;
use Enums\Channels;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductCategoryRequests implements RequestInterface
{
    /**
     * @param string|null $publishedAtMin
     * @param string|null $publishedAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromShopify(string $publishedAtMin = null, string $publishedAtMax = null, array $fields = null, object $filters = null, string|bool $resume = true): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $sourceCustomCollections = $shopifyClient->getAllCustomCollections(
            fields: $fields,
            ids: $filters->ids ?? null,
            publishedAtMin: $publishedAtMin,
            publishedAtMax: $publishedAtMax,
            sinceId: $filters->sinceId ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
        );
        $sourceSmartCollections = $shopifyClient->getAllSmartCollections(
            fields: $fields,
            ids: $filters->ids ?? null,
            publishedAtMin: $publishedAtMin,
            publishedAtMax: $publishedAtMax,
            sinceId: $filters->sinceId ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            pageInfo: $filters->pageInfo ?? null,
        );
        $sourceCollects = $shopifyClient->getAllCollects(
            pageInfo: $filters->pageInfo ?? null,
        );
        return self::process(
            new ArrayCollection(
                [
                    ...ShopifyConvert::productCategories(productCategories: $sourceCustomCollections['custom_collections'])->toArray(),
                    ...ShopifyConvert::productCategories(productCategories: $sourceSmartCollections['smart_collections'], isSmartCollection: true)->toArray()
                ]
            ),
            ShopifyConvert::collects($sourceCollects['collects'])->toArray()
        );
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
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromNetsuite(object $filters = null, string|bool $resume = true): Response
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
        $channeledProductCategoryRepository = $manager->getRepository(entityName: ChanneledProductCategory::class);
        $lastChanneledProductCategory = $channeledProductCategoryRepository->getLastByPlatformId(channel: Channels::netsuite->value);

        $query = "SELECT * FROM CommerceCategory WHERE id >= " . (isset($lastChanneledProductCategory['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledProductCategory['platformId'] : 0) . " ORDER BY id ASC";
        $netsuiteClient->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function($productCategories) {
                self::process(NetSuiteConvert::productCategories($productCategories));
            }
        );
        return new Response(json_encode(['Product Categories retrieved.']));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param array|null $collects
     * @return Response
     * @throws ORMException
     */
    public static function process(ArrayCollection $channeledCollection, ?array $collects = null): Response
    {
        try {
            $manager = Helpers::getManager();
            $repos = self::initializeRepositories(manager: $manager);

            foreach ($channeledCollection as $productCategory) {
                self::processSingleProductCategory(
                    productCategory: $productCategory,
                    collects: $collects,
                    repos: $repos,
                    manager: $manager
                );
            }

            return new Response(content: json_encode(value: ['Categories processed']));
        } catch (Exception $e) {
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
            'productCategory' => $manager->getRepository(entityName: ProductCategory::class),
            'channeledProductCategory' => $manager->getRepository(entityName: ChanneledProductCategory::class),
            'channeledProduct' => $manager->getRepository(entityName: ChanneledProduct::class),
        ];
    }

    /**
     * @param object $productCategory
     * @param array|null $collects
     * @param array $repos
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processSingleProductCategory(object $productCategory, ?array $collects, array $repos, EntityManager $manager): void
    {
        $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());

        $productCategoryEntity = self::getOrCreateProductCategory(
            productCategory: $productCategory,
            repository: $repos['productCategory']
        );

        $channeledProductCategoryEntity = self::getOrCreateChanneledProductCategory(
            productCategory: $productCategory,
            repository: $repos['channeledProductCategory']
        );

        self::updateChanneledProductCategoryData(
            productCategory: $productCategory,
            channeledProductCategoryEntity: $channeledProductCategoryEntity
        );

        $productIds = [];
        if ($collects && isset($collects[$productCategory->platformId])) {
            $productIds = self::processCollects(
                productIds: $collects[$productCategory->platformId],
                channeledProductCategoryEntity: $channeledProductCategoryEntity,
                channel: $productCategory->channel,
                repository: $repos['channeledProduct'],
                manager: $manager
            );
        }

        self::finalizeCategoryRelationships(
            productCategoryEntity: $productCategoryEntity,
            channeledProductCategoryEntity: $channeledProductCategoryEntity,
            manager: $manager
        );

        // Invalidate caches for all affected entities
        $entities = [
            'ProductCategory' => $productCategoryEntity->getProductCategoryId(),
            'ChanneledProductCategory' => $channeledProductCategoryEntity->getPlatformId(),
            'ChanneledProduct' => $productIds,
        ];
        $cacheService->invalidateMultipleEntities(
            entities: array_filter($entities, fn($value) => !empty($value)),
            channel: $productCategory->channel
        );
    }

    /**
     * @param object $productCategory
     * @param EntityRepository $repository
     * @return ProductCategory
     */
    private static function getOrCreateProductCategory(object $productCategory, EntityRepository $repository): ProductCategory
    {
        return $repository->existsByProductCategoryId(productCategoryId: $productCategory->platformId)
            ? $repository->getByProductCategoryId(productCategoryId: $productCategory->platformId)
            : $repository->create(
                data: (object) [
                    'productCategoryId' => $productCategory->platformId,
                    'isSmartCollection' => $productCategory->isSmartCollection,
                ],
                returnEntity: true
            );
    }

    /**
     * @param object $productCategory
     * @param EntityRepository $repository
     * @return ChanneledProductCategory
     */
    private static function getOrCreateChanneledProductCategory(object $productCategory, EntityRepository $repository): ChanneledProductCategory
    {
        return $repository->existsByPlatformId(platformId: $productCategory->platformId, channel: $productCategory->channel)
            ? $repository->getByPlatformId(platformId: $productCategory->platformId, channel: $productCategory->channel)
            : $repository->create(
                data: $productCategory,
                returnEntity: true
            );
    }

    /**
     * @param object $productCategory
     * @param ChanneledProductCategory $channeledProductCategoryEntity
     * @return void
     */
    private static function updateChanneledProductCategoryData(object $productCategory, ChanneledProductCategory $channeledProductCategoryEntity): void
    {
        if (empty($channeledProductCategoryEntity->getData())) {
            $channeledProductCategoryEntity
                ->addPlatformId(platformId: $productCategory->platformId)
                ->addIsSmartCollection(isSmartCollection: $productCategory->isSmartCollection)
                ->addPlatformCreatedAt(platformCreatedAt: $productCategory->platformCreatedAt)
                ->addData(data: $productCategory->data);
        }
    }

    /**
     * @param array $productIds
     * @param ChanneledProductCategory $channeledProductCategoryEntity
     * @param string $channel
     * @param EntityRepository $repository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processCollects(
        array $productIds,
        ChanneledProductCategory $channeledProductCategoryEntity,
        string $channel,
        EntityRepository $repository,
        EntityManager $manager
    ): array {
        $collectedProductIds = [];

        foreach ($productIds as $productId) {
            $channeledProductEntity = $repository->existsByPlatformId(platformId: $productId, channel: $channel)
                ? $repository->getByPlatformId(platformId: $productId, channel: $channel)
                : $repository->create(
                    data: (object) [
                        'channel' => $channel,
                        'platformId' => $productId,
                        'data' => [],
                    ],
                    returnEntity: true
                );

            $channeledProductCategoryEntity->addChanneledProduct(channeledProduct: $channeledProductEntity);
            $manager->persist(entity: $channeledProductEntity);
            $manager->persist(entity: $channeledProductCategoryEntity);
            $manager->flush();

            $collectedProductIds[] = $productId;
        }

        return array_unique($collectedProductIds);
    }

    /**
     * @param ProductCategory $productCategoryEntity
     * @param ChanneledProductCategory $channeledProductCategoryEntity
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function finalizeCategoryRelationships(
        ProductCategory $productCategoryEntity,
        ChanneledProductCategory $channeledProductCategoryEntity,
        EntityManager $manager
    ): void {
        $productCategoryEntity->addChanneledProductCategory(channeledProductCategory: $channeledProductCategoryEntity);
        $manager->persist(entity: $productCategoryEntity);
        $manager->persist(entity: $channeledProductCategoryEntity);
        $manager->flush();
    }
}
