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
            sinceId: $filters->sinceId ?? isset($lastChanneledProduct['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledProduct['platformId'] : null,
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
     * @throws Exception
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
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function process(
        ArrayCollection $channeledCollection,
    ): Response {
        $manager = Helpers::getManager();
        $productRepository = $manager->getRepository(entityName: Product::class);
        $productVariantRepository = $manager->getRepository(entityName: ProductVariant::class);
        $productCategoryRepository = $manager->getRepository(entityName: ProductCategory::class);
        $vendorRepository = $manager->getRepository(entityName: Vendor::class);
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $channeledProductVariantRepository = $manager->getRepository(entityName: ChanneledProductVariant::class);
        $channeledProductCategoryRepository = $manager->getRepository(entityName: ChanneledProductCategory::class);
        $channeledVendorRepository = $manager->getRepository(entityName: ChanneledVendor::class);
        foreach ($channeledCollection as $channeledProduct) {
            // Process Product
            if (!$productRepository->existsByProductId($channeledProduct->platformId)) {
                $productEntity = $productRepository->create(
                    data: (object) [
                        'productId' => $channeledProduct->platformId,
                        'sku' => $channeledProduct->sku,
                    ],
                    returnEntity: true,
                );
            } else {
                $productEntity = $productRepository->getByProductId($channeledProduct->platformId);
            }
            // Process Channeled Product
            if (!$channeledProductRepository->existsByPlatformId($channeledProduct->platformId,
                $channeledProduct->channel)) {
                $channeledProductEntity = $channeledProductRepository->create(
                    data: $channeledProduct,
                    returnEntity: true,
                );
            } else {
                $channeledProductEntity = $channeledProductRepository->getByPlatformId($channeledProduct->platformId,
                    $channeledProduct->channel);
            }
            if (empty($channeledProductEntity->getData())) {
                $channeledProductEntity
                    ->addPlatformId($channeledProduct->platformId)
                    ->addPlatformCreatedAt($channeledProduct->platformCreatedAt)
                    ->addData($channeledProduct->data);
            }
            // Process Vendor
            if (isset($channeledProduct->vendor->name)) {
                if (!$vendorRepository->existsByName($channeledProduct->vendor->name)) {
                    $vendorEntity = $vendorRepository->create(
                        data: (object) [
                            'name' => $channeledProduct->vendor,
                        ],
                        returnEntity: true,
                    );
                } else {
                    $vendorEntity = $vendorRepository->getByName($channeledProduct->vendor->name);
                }
                if (!$channeledVendorRepository->existsByName($channeledProduct->vendor->name,
                    $channeledProduct->channel)) {
                    $channeledVendorEntity = $channeledVendorRepository->create(
                        data: $channeledProduct->vendor,
                        returnEntity: true,
                    );
                } else {
                    $channeledVendorEntity = $channeledVendorRepository->getByName($channeledProduct->vendor->name,
                        $channeledProduct->channel);
                }
                if (!empty($channeledVendorEntity->getData())) {
                    $channeledVendorEntity
                        ->addPlatformId($channeledProduct->vendor->platformId)
                        ->addPlatformCreatedAt($channeledProduct->vendor->platformCreatedAt)
                        ->addData($channeledProduct->vendor->data);
                }
                $channeledProductEntity->addChanneledVendor($channeledVendorEntity);
                $vendorEntity->addChanneledVendor($channeledVendorEntity);
                $manager->persist($vendorEntity);
                $manager->persist($channeledVendorEntity);
            }
            // Process Variants
            foreach ($channeledProduct->variants as $productVariant) {
                if (!$productVariantRepository->existsByProductVariantId($productVariant->platformId)) {
                    $productVariantEntity = $productVariantRepository->create(
                        data: (object) [
                            'productVariantId' => $productVariant->platformId,
                            'sku' => $productVariant->sku,
                        ],
                        returnEntity: true,
                    );
                } else {
                    $productVariantEntity = $productVariantRepository->getByProductVariantId($productVariant->platformId);
                }
                if (!$channeledProductVariantRepository->existsByPlatformId($productVariant->platformId,
                    $channeledProduct->channel)) {
                    $channeledProductVariantEntity = $channeledProductVariantRepository->create(
                        data: $productVariant,
                        returnEntity: true,
                    );
                } else {
                    $channeledProductVariantEntity = $channeledProductVariantRepository->getByPlatformId($productVariant->platformId,
                        $channeledProduct->channel);
                }
                if (empty($channeledProductVariantEntity->getData())) {
                    $channeledProductVariantEntity
                        ->addPlatformId($productVariant->platformId)
                        ->addPlatformCreatedAt($productVariant->platformCreatedAt)
                        ->addData($productVariant->data);
                }
                $productVariantEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $channeledProductEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $manager->persist($productVariantEntity);
                $manager->persist($channeledProductVariantEntity);
                $manager->flush();
            }
            // Process Categories
            if (!empty($channeledProduct->categories)) {
                foreach ($channeledProduct->categories as $category) {
                    if (!$productCategoryRepository->existsByProductCategoryId($category->platformId)) {
                        $productCategoryEntity = $productCategoryRepository->create(
                            data: (object) [
                                'productCategoryId' => $category->platformId,
                                'isSmartCollection' => $category->isSmartCollection,
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $productCategoryEntity = $productCategoryRepository->getByProductCategoryId($category->platformId);
                    }
                    if (!$channeledProductCategoryRepository->existsByPlatformId($category->platformId,
                        $channeledProduct->channel)) {
                        $channeledProductCategoryEntity = $channeledProductCategoryRepository->create(
                            data: $category,
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductCategoryEntity = $channeledProductCategoryRepository->getByPlatformId($category->platformId,
                            $channeledProduct->channel);
                    }
                    if (empty($channeledProductCategoryEntity->getData())) {
                        $channeledProductCategoryEntity
                            ->addPlatformId($category->platformId)
                            ->addPlatformCreatedAt($category->platformCreatedAt)
                            ->addData($category->data);
                    }
                    $productCategoryEntity->addChanneledProductCategory($channeledProductCategoryEntity);
                    $channeledProductCategoryEntity->addChanneledProduct($channeledProductEntity);
                    $manager->persist($productCategoryEntity);
                    $manager->persist($channeledProductCategoryEntity);
                    $manager->flush();
                }
            }
            // Persist Product and Channeled Product
            $productEntity->addChanneledProduct($channeledProductEntity);
            $manager->persist($productEntity);
            $manager->persist($channeledProductEntity);
            $manager->flush();
        }
        return new Response(json_encode(['Products processed']));
    }
}