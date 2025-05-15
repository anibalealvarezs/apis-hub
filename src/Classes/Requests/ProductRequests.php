<?php

namespace Classes\Requests;

use Anibalealvarezs\KlaviyoApi\KlaviyoApi;
use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Conversions\KlaviyoConvert;
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
use Entities\Analytics\Channeled\ChanneledProductVariant;
use Entities\Analytics\Channeled\ChanneledVendor;
use Entities\Analytics\Product;
use Entities\Analytics\ProductCategory;
use Entities\Analytics\ProductVariant;
use Entities\Analytics\Vendor;
use Enums\Channels;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductRequests implements RequestInterface
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param int|null $collectionId
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, int $collectionId = null, array $fields = null, object $filters = null, string|bool $resume = true): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $lastChanneledProduct = $channeledProductRepository->getLastByPlatformId(channel: Channels::shopify->value);

        $shopifyClient->getAllProductsAndProcess(
            collectionId: $collectionId,
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            fields: $fields,
            handle: $filters->handle ?? null,
            ids: $filters->ids ?? null,
            presentmentCurrencies: $filters->presentmentCurrencies ?? null,
            productType: $filters->productType ?? null,
            publishedAtMin: $filters->publishedAtMin ?? null,
            publishedAtMax: $filters->publishedAtMax ?? null,
            sinceId: $filters->sinceId ?? (isset($lastChanneledProduct['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledProduct['platformId'] : null),
            status: $filters->status ?? null,
            title: $filters->title ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            vendor: $filters->vendor ?? null,
            pageInfo: $filters->pageInfo ?? null,
            callback: function($products) {
                self::process(ShopifyConvert::products($products));
            }
        );
        return new Response(json_encode(['Products retrieved']));
    }

    /**
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromKlaviyo(array $fields = null, object $filters = null, string|bool $resume = true): Response
    {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );
        $formattedFilters = [];
        if ($filters) {
            foreach ($filters as $key => $value) {
                $formattedFilters[] = [
                    "operator" => 'equals',
                    "field" => $key,
                    "value" => $value,
                ];
            }
        }
        $sourceProducts = $klaviyoClient->getAllCatalogItems(
            catalogItemsFields: $fields,
            filter: $formattedFilters,
        );
        return self::process(KlaviyoConvert::products($sourceProducts['data']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(object $filters = null, string|bool $resume = true): Response
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
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $lastChanneledProduct = $channeledProductRepository->getLastByPlatformId(channel: Channels::netsuite->value);

        $query = "SELECT
                Item.*,
                CUSTOMLIST_ITEM_STYLE.Id AS StyleID,
                CUSTOMLIST_ITEM_STYLE.Name AS StyleName,
                CUSTOMLIST_ITEM_SIZE.Id AS SizeID,
                CUSTOMLIST_ITEM_SIZE.Name AS SizeName,
                ItemInventoryBalance.quantityavailable,
                ItemInventoryBalance.quantityonhand,
                ItemInventoryBalance.quantitypicked,
                ItemPrice.price AS itemprice,
                CommerceCategory.id AS CommerceCategoryId,
                CUSTOMLIST_ITEM_CATEGORY.Name AS CategoryName,
                CUSTOMLIST_ITEM_CATEGORY.Id AS CategoryID,
                CUSTOMLIST_ITEM_COLOR.id AS ColorID,
                CUSTOMLIST_ITEM_COLOR.name AS ColorName,
                ItemCollection.id AS CollectionID,
                ItemCollection.name AS CollectionName,
                CUSTOMRECORD_WEBSTORES.name as storeName,
                CUSTOMRECORD_DESIGN.custrecord_deadline_date AS designdeadlinedate,
                CUSTOMRECORD_DESIGN.custrecord_nssca_cannot_ship_after_date AS designcannotshipafterdate
            FROM Item
            LEFT JOIN CUSTOMLIST_ITEM_STYLE
                ON Item.custitem_item_style = CUSTOMLIST_ITEM_STYLE.id
            LEFT JOIN CUSTOMLIST_ITEM_SIZE
                ON item.custitem_item_size = CUSTOMLIST_ITEM_SIZE.id
            LEFT JOIN ItemInventoryBalance
                ON Item.id = ItemInventoryBalance.item
            LEFT JOIN ItemPrice
                ON Item.id = ItemPrice.item
            LEFT JOIN CUSTOMLIST_ITEM_CATEGORY
                ON Item.custitem_item_category = CUSTOMLIST_ITEM_CATEGORY.id
            LEFT JOIN CUSTOMLIST_ITEM_COLOR
                ON Item.custitem_item_color = CUSTOMLIST_ITEM_COLOR.id
            LEFT JOIN ItemCollectionItemSimpleMap
                ON Item.id = ItemCollectionItemSimpleMap.item
            LEFT JOIN ItemCollection
                ON ItemCollectionItemSimpleMap.itemcollection = ItemCollection.id
            LEFT JOIN CommerceCategoryItemAssociation
                ON CommerceCategoryItemAssociation.item = Item.id
            LEFT JOIN CommerceCategory
                ON CommerceCategoryItemAssociation.category = CommerceCategory.id
            LEFT JOIN MAP_item_custitem_awa_display_in_webstore
                ON item.id = MAP_item_custitem_awa_display_in_webstore.mapone
            LEFT JOIN CUSTOMRECORD_WEBSTORES
                ON MAP_item_custitem_awa_display_in_webstore.maptwo = CUSTOMRECORD_WEBSTORES.id
            LEFT JOIN CUSTOMRECORD_DESIGN
                ON Item.custitem_design_code = CUSTOMRECORD_DESIGN.id
            WHERE Item.itemtype IN ('NonInvtPart', 'Assembly')
                AND (CUSTOMRECORD_WEBSTORES.name IS NULL OR CUSTOMRECORD_WEBSTORES.name = '".$config['netsuite_store_name']."')
                AND Item.createddate >= TO_DATE('01/01/2020', 'mm/dd/yyyy')
                AND Item.id >= " . (isset($lastChanneledProduct['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledProduct['platformId'] : 0);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $query .= " AND Item.$key = '$value'";
            }
        }
        $query .= " ORDER BY Item.id ASC";
        $netsuiteClient->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function($products) use ($netsuiteClient, $config) {
                $convertedProductsArray = NetSuiteConvert::products($products)->toArray();
                $productsIds = array_map(function($product) {
                    return $product->platformId;
                }, $convertedProductsArray);
                if (!empty($productsIds)) {
                    usleep(500000); // Delay to prevent rate limit issues between the `items` and `images` queries
                    $images = $netsuiteClient->getImagesForProducts(
                        store: $config['netsuite_store_name'],
                        productsIds: array_values($productsIds),
                    );
                    if ($images['count'] == 0) {
                        usleep(500000); // Delay to prevent rate limit issues between the `images` and `items` queries
                    }
                    $keyedImages = [];
                    foreach($images['items'] as $image) {
                        if (!isset($keyedImages[$image['item']])) {
                            $keyedImages[$image['item']] = [];
                        }
                        $keyedImages[$image['item']][] = [
                            'name' => $image['name'],
                            'url' => $config['netsuite_store_base_url'] . (!str_ends_with($config['netsuite_store_base_url'], '/') ? '/' : '') . 'site/images/' . $image['name'],
                        ];
                    }
                    foreach($convertedProductsArray as &$product) {
                        $product->data['images'] = array_map(function($image) {
                            return $image['url'];
                        }, $keyedImages[$product->platformId] ?? []);
                        foreach($product->variants as &$variant) {
                            $variant->data['images'] = [];
                            if (!isset($keyedImages[$product->platformId])) {
                                continue;
                            }
                            foreach($keyedImages[$product->platformId] as $image) {
                                $cleanNameArray = explode('.', $image['name']);
                                if (str_starts_with($variant->sku, $cleanNameArray[0])) {
                                    $variant->data['images'][] = $image['url'];
                                }
                            }
                        }
                    }
                }
                $convertedProducts = new ArrayCollection($convertedProductsArray);
                self::process($convertedProducts);
            }
        );
        return new Response(json_encode(['Products retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true): Response
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
        try {
            $manager = Helpers::getManager();
            $repos = self::initializeRepositories(manager: $manager);

            foreach ($channeledCollection as $channeledProduct) {
                self::processSingleProduct(
                    channeledProduct: $channeledProduct,
                    repos: $repos,
                    manager: $manager
                );
            }

            return new Response(content: json_encode(value: ['Products processed']));
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
            'product' => $manager->getRepository(entityName: Product::class),
            'productVariant' => $manager->getRepository(entityName: ProductVariant::class),
            'productCategory' => $manager->getRepository(entityName: ProductCategory::class),
            'vendor' => $manager->getRepository(entityName: Vendor::class),
            'channeledProduct' => $manager->getRepository(entityName: ChanneledProduct::class),
            'channeledProductVariant' => $manager->getRepository(entityName: ChanneledProductVariant::class),
            'channeledProductCategory' => $manager->getRepository(entityName: ChanneledProductCategory::class),
            'channeledVendor' => $manager->getRepository(entityName: ChanneledVendor::class),
        ];
    }

    /**
     * @param object $channeledProduct
     * @param array $repos
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processSingleProduct(object $channeledProduct, array $repos, EntityManager $manager): void
    {
        $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());

        $productEntity = self::getOrCreateProduct(
            channeledProduct: $channeledProduct,
            repository: $repos['product']
        );

        $channeledProductEntity = self::getOrCreateChanneledProduct(
            channeledProduct: $channeledProduct,
            repository: $repos['channeledProduct']
        );

        self::updateChanneledProductData(
            channeledProduct: $channeledProduct,
            channeledProductEntity: $channeledProductEntity
        );

        $vendorIds = self::processVendor(
            channeledProduct: $channeledProduct,
            channeledProductEntity: $channeledProductEntity,
            vendorRepository: $repos['vendor'],
            channeledVendorRepository: $repos['channeledVendor'],
            manager: $manager
        );

        $variantIds = self::processVariants(
            variants: $channeledProduct->variants,
            channeledProductEntity: $channeledProductEntity,
            productVariantRepository: $repos['productVariant'],
            channeledProductVariantRepository: $repos['channeledProductVariant'],
            manager: $manager
        );

        $categoryIds = self::processCategories(
            categories: $channeledProduct->categories,
            channeledProductEntity: $channeledProductEntity,
            productCategoryRepository: $repos['productCategory'],
            channeledProductCategoryRepository: $repos['channeledProductCategory'],
            manager: $manager
        );

        self::finalizeProductRelationships(
            productEntity: $productEntity,
            channeledProductEntity: $channeledProductEntity,
            manager: $manager
        );

        // Invalidate caches for all affected entities
        $entities = [
            'Product' => $productEntity->getProductId(),
            'ChanneledProduct' => $channeledProductEntity->getPlatformId(),
            'ProductVariant' => $variantIds['productVariantIds'],
            'ChanneledProductVariant' => $variantIds['channeledProductVariantIds'],
            'Vendor' => $vendorIds['vendorNames'],
            'ChanneledVendor' => $vendorIds['channeledVendorNames'],
            'ProductCategory' => $categoryIds['productCategoryIds'],
            'ChanneledProductCategory' => $categoryIds['channeledProductCategoryIds'],
        ];
        $cacheService->invalidateMultipleEntities(
            entities: array_filter($entities, fn($value) => !empty($value)),
            channel: $channeledProduct->channel
        );
    }

    /**
     * @param object $channeledProduct
     * @param EntityRepository $repository
     * @return Product
     */
    private static function getOrCreateProduct(object $channeledProduct, EntityRepository $repository): Product
    {
        return $repository->existsByProductId(productId: $channeledProduct->platformId)
            ? $repository->getByProductId(productId: $channeledProduct->platformId)
            : $repository->create(
                data: (object) [
                    'productId' => $channeledProduct->platformId,
                    'sku' => $channeledProduct->sku,
                ],
                returnEntity: true
            );
    }

    /**
     * @param object $channeledProduct
     * @param EntityRepository $repository
     * @return ChanneledProduct
     */
    private static function getOrCreateChanneledProduct(object $channeledProduct, EntityRepository $repository): ChanneledProduct
    {
        return $repository->existsByPlatformId(platformId: $channeledProduct->platformId, channel: $channeledProduct->channel)
            ? $repository->getByPlatformId(platformId: $channeledProduct->platformId, channel: $channeledProduct->channel)
            : $repository->create(
                data: $channeledProduct,
                returnEntity: true
            );
    }

    /**
     * @param object $channeledProduct
     * @param ChanneledProduct $channeledProductEntity
     * @return void
     */
    private static function updateChanneledProductData(object $channeledProduct, ChanneledProduct $channeledProductEntity): void
    {
        if (empty($channeledProductEntity->getData())) {
            $channeledProductEntity
                ->addPlatformId(platformId: $channeledProduct->platformId)
                ->addPlatformCreatedAt(platformCreatedAt: $channeledProduct->platformCreatedAt)
                ->addData(data: $channeledProduct->data);
        }
    }

    /**
     * @param object $channeledProduct
     * @param ChanneledProduct $channeledProductEntity
     * @param EntityRepository $vendorRepository
     * @param EntityRepository $channeledVendorRepository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     */
    private static function processVendor(
        object $channeledProduct,
        ChanneledProduct $channeledProductEntity,
        EntityRepository $vendorRepository,
        EntityRepository $channeledVendorRepository,
        EntityManager $manager
    ): array {
        if (!isset($channeledProduct->vendor->name)) {
            return ['vendorNames' => [], 'channeledVendorNames' => []];
        }

        $vendorEntity = $vendorRepository->existsByName(name: $channeledProduct->vendor->name)
            ? $vendorRepository->getByName(name: $channeledProduct->vendor->name)
            : $vendorRepository->create(
                data: (object) ['name' => $channeledProduct->vendor->name],
                returnEntity: true
            );

        $channeledVendorEntity = $channeledVendorRepository->existsByName(name: $channeledProduct->vendor->name, channel: $channeledProduct->channel)
            ? $channeledVendorRepository->getByName(name: $channeledProduct->vendor->name, channel: $channeledProduct->channel)
            : $channeledVendorRepository->create(
                data: $channeledProduct->vendor,
                returnEntity: true
            );

        if (!empty($channeledVendorEntity->getData())) {
            $channeledVendorEntity
                ->addPlatformId(platformId: $channeledProduct->vendor->platformId)
                ->addPlatformCreatedAt(platformCreatedAt: $channeledProduct->vendor->platformCreatedAt)
                ->addData(data: $channeledProduct->vendor->data);
        }

        $channeledProductEntity->addChanneledVendor(channeledVendor: $channeledVendorEntity);
        $vendorEntity->addChanneledVendor(channeledVendor: $channeledVendorEntity);

        $manager->persist(entity: $vendorEntity);
        $manager->persist(entity: $channeledVendorEntity);

        return [
            'vendorNames' => [$vendorEntity->getName()],
            'channeledVendorNames' => [$channeledVendorEntity->getName()],
        ];
    }

    /**
     * @param array $variants
     * @param ChanneledProduct $channeledProductEntity
     * @param EntityRepository $productVariantRepository
     * @param EntityRepository $channeledProductVariantRepository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processVariants(
        array $variants,
        ChanneledProduct $channeledProductEntity,
        EntityRepository $productVariantRepository,
        EntityRepository $channeledProductVariantRepository,
        EntityManager $manager
    ): array {
        $productVariantIds = [];
        $channeledProductVariantIds = [];

        foreach ($variants as $productVariant) {
            $productVariantEntity = $productVariantRepository->existsByProductVariantId(productVariantId: $productVariant->platformId)
                ? $productVariantRepository->getByProductVariantId(productVariantId: $productVariant->platformId)
                : $productVariantRepository->create(
                    data: (object) [
                        'productVariantId' => $productVariant->platformId,
                        'sku' => $productVariant->sku,
                    ],
                    returnEntity: true
                );

            $channeledProductVariantEntity = $channeledProductVariantRepository->existsByPlatformId(platformId: $productVariant->platformId, channel: $channeledProductEntity->getChannel())
                ? $channeledProductVariantRepository->getByPlatformId(platformId: $productVariant->platformId, channel: $channeledProductEntity->getChannel())
                : $channeledProductVariantRepository->create(
                    data: $productVariant,
                    returnEntity: true
                );

            if (empty($channeledProductVariantEntity->getData())) {
                $channeledProductVariantEntity
                    ->addPlatformId(platformId: $productVariant->platformId)
                    ->addPlatformCreatedAt(platformCreatedAt: $productVariant->platformCreatedAt)
                    ->addData(data: $productVariant->data);
            }

            $productVariantEntity->addChanneledProductVariant(channeledProductVariant: $channeledProductVariantEntity);
            $channeledProductEntity->addChanneledProductVariant(channeledProductVariant: $channeledProductVariantEntity);

            $manager->persist(entity: $productVariantEntity);
            $manager->persist(entity: $channeledProductVariantEntity);
            $manager->flush();

            $productVariantIds[] = $productVariantEntity->getProductVariantId();
            $channeledProductVariantIds[] = $channeledProductVariantEntity->getPlatformId();
        }

        return [
            'productVariantIds' => array_unique($productVariantIds),
            'channeledProductVariantIds' => array_unique($channeledProductVariantIds),
        ];
    }

    /**
     * @param array $categories
     * @param ChanneledProduct $channeledProductEntity
     * @param EntityRepository $productCategoryRepository
     * @param EntityRepository $channeledProductCategoryRepository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function processCategories(
        array $categories,
        ChanneledProduct $channeledProductEntity,
        EntityRepository $productCategoryRepository,
        EntityRepository $channeledProductCategoryRepository,
        EntityManager $manager
    ): array {
        if (empty($categories)) {
            return ['productCategoryIds' => [], 'channeledProductCategoryIds' => []];
        }

        $productCategoryIds = [];
        $channeledProductCategoryIds = [];

        foreach ($categories as $category) {
            $productCategoryEntity = $productCategoryRepository->existsByProductCategoryId(productCategoryId: $category->platformId)
                ? $productCategoryRepository->getByProductCategoryId(productCategoryId: $category->platformId)
                : $productCategoryRepository->create(
                    data: (object) [
                        'productCategoryId' => $category->platformId,
                        'isSmartCollection' => $category->isSmartCollection,
                    ],
                    returnEntity: true
                );

            $channeledProductCategoryEntity = $channeledProductCategoryRepository->existsByPlatformId(platformId: $category->platformId, channel: $channeledProductEntity->getChannel())
                ? $channeledProductCategoryRepository->getByPlatformId(platformId: $category->platformId, channel: $channeledProductEntity->getChannel())
                : $channeledProductCategoryRepository->create(
                    data: $category,
                    returnEntity: true
                );

            if (empty($channeledProductCategoryEntity->getData())) {
                $channeledProductCategoryEntity
                    ->addPlatformId(platformId: $category->platformId)
                    ->addPlatformCreatedAt(platformCreatedAt: $category->platformCreatedAt)
                    ->addData(data: $category->data);
            }

            $productCategoryEntity->addChanneledProductCategory(channeledProductCategory: $channeledProductCategoryEntity);
            $channeledProductCategoryEntity->addChanneledProduct(channeledProduct: $channeledProductEntity);

            $manager->persist(entity: $productCategoryEntity);
            $manager->persist(entity: $channeledProductCategoryEntity);
            $manager->flush();

            $productCategoryIds[] = $productCategoryEntity->getProductCategoryId();
            $channeledProductCategoryIds[] = $channeledProductCategoryEntity->getPlatformId();
        }

        return [
            'productCategoryIds' => array_unique($productCategoryIds),
            'channeledProductCategoryIds' => array_unique($channeledProductCategoryIds),
        ];
    }

    /**
     * @param Product $productEntity
     * @param ChanneledProduct $channeledProductEntity
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function finalizeProductRelationships(
        Product $productEntity,
        ChanneledProduct $channeledProductEntity,
        EntityManager $manager
    ): void {
        $productEntity->addChanneledProduct(channeledProduct: $channeledProductEntity);
        $manager->persist(entity: $productEntity);
        $manager->persist(entity: $channeledProductEntity);
        $manager->flush();
    }
}
