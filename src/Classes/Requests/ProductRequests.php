<?php

declare(strict_types=1);

namespace Classes\Requests;

use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
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
use Enums\Channel;
use Repositories\Channeled\ChanneledProductCategoryRepository;
use Repositories\Channeled\ChanneledProductRepository;
use Repositories\Channeled\ChanneledProductVariantRepository;
use Repositories\Channeled\ChanneledVendorRepository;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Repositories\ProductCategoryRepository;
use Repositories\ProductRepository;
use Repositories\ProductVariantRepository;
use Repositories\VendorRepository;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductRequests implements RequestInterface
{
    /**
     * @return \Enums\Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::klaviyo,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
        ];
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param string|int|null $collectionId
     * @param array|null $fields
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
        string|int|null $collectionId = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true, ?int $jobId = null): Response {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $manager = Helpers::getManager();
        /** @var ChanneledProductRepository $channeledProductRepository */
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $lastChanneledProduct = $channeledProductRepository->getLastByPlatformId(channel: Channel::shopify->value);

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
            callback: function($products) use ($jobId) {
                Helpers::checkJobStatus($jobId);
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
     */
    public static function getListFromKlaviyo(
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true, ?int $jobId = null): Response {
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

        $klaviyoClient->getAllCatalogItemsAndProcess(
            catalogItemsFields: $fields,
            filter: $formattedFilters,
            callback: function ($products) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(KlaviyoConvert::products($products));
            }
        );

        return new Response(json_encode(['Products retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
    public static function getListFromNetsuite(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
        /** @var ChanneledProductRepository $channeledProductRepository */
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $lastChanneledProduct = $channeledProductRepository->getLastByPlatformId(channel: Channel::netsuite->value);

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
            callback: function($products) use ($netsuiteClient, $config, $jobId) {
                Helpers::checkJobStatus($jobId);
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
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
            
            $result = \Classes\ProductProcessor::processProducts($channeledCollection, $manager);
            
            if (!empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $entities = [
                    'Product' => $result['products'],
                    'ChanneledProduct' => $result['channeledProducts'],
                    'Vendor' => $result['vendors'],
                    // 'ChanneledVendor' => $vendorIds['channeledVendorNames'],
                    'ProductVariant' => $result['productVariants'],
                    // 'ChanneledProductVariant' => $variantIds['channeledProductVariantIds'],
                    'ProductCategory' => $result['productCategories'],
                    // 'ChanneledProductCategory' => $categoryIds['channeledProductCategoryIds'],
                ];
                
                $channelName = Channel::from(reset($result['channels']))->getName(); 
                
                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn($value) => !empty($value)),
                    channel: $channelName
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
}
