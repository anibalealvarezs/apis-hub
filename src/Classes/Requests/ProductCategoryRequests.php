<?php

namespace Classes\Requests;

use Chmw\KlaviyoApi\KlaviyoApi;
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
use Entities\Analytics\ProductCategory;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductCategoryRequests implements RequestInterface
{
    /**
     * @param string|null $publishedAtMin
     * @param string|null $publishedAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromShopify(string $publishedAtMin = null, string $publishedAtMax = null, array $fields = null, object $filters = null): Response
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
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromKlaviyo(array $fields = null, object $filters = null): Response
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
        $sourceCategories = $klaviyoClient->getAllCatalogVariants(
            catalogVariantsFields: $fields,
            filter: $formattedFilters,
        );
        return self::process(KlaviyoConvert::productCategories(productCategories: $sourceCategories['data']));
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
     * @param array|null $collects
     * @return Response
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function process(
        ArrayCollection $channeledCollection,
        ?array $collects = null,
    ): Response {
        $manager = Helpers::getManager();
        $productCategoryRepository = $manager->getRepository(entityName: ProductCategory::class);
        $channeledProductCategoryRepository = $manager->getRepository(entityName: ChanneledProductCategory::class);
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        foreach ($channeledCollection as $productCategory) {
            if (!$productCategoryRepository->existsByProductCategoryId($productCategory->platformId)) {
                $productCategoryEntity = $productCategoryRepository->create(
                    data: (object) [
                        'productCategoryId' => $productCategory->platformId,
                        'isSmartCollection' => $productCategory->isSmartCollection,
                    ],
                    returnEntity: true,
                );
            } else {
                $productCategoryEntity = $productCategoryRepository->getByProductCategoryId($productCategory->platformId);
            }
            if (!$channeledProductCategoryRepository->existsByPlatformId($productCategory->platformId, $productCategory->channel)) {
                $channeledProductCategoryEntity = $channeledProductCategoryRepository->create(
                    data: $productCategory,
                    returnEntity: true,
                );
            } else {
                $channeledProductCategoryEntity = $channeledProductCategoryRepository->getByPlatformId($productCategory->platformId, $productCategory->channel);
            }
            if (empty($channeledProductCategoryEntity->getData())) {
                $channeledProductCategoryEntity
                    ->addPlatformId($productCategory->platformId)
                    ->addIsSmartCollection($productCategory->isSmartCollection)
                    ->addData($productCategory->data);
            }
            if ($collects && isset($collects[$productCategory->platformId])) {
                foreach($collects[$productCategory->platformId] as $productId) {
                    if (!$channeledProductRepository->existsByPlatformId($productId, $productCategory->channel)) {
                        $channeledProductEntity = $channeledProductRepository->create(
                            data: (object) [
                                'channel' => $productCategory->channel,
                                'platformId' => $productId,
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
                    } else {
                        $channeledProductEntity = $channeledProductRepository->getByPlatformId($productId, $productCategory->channel);
                    }
                    $channeledProductCategoryEntity->addChanneledProduct($channeledProductEntity);
                    $manager->persist($channeledProductEntity);
                    $manager->persist($channeledProductCategoryEntity);
                    $manager->flush();
                }
            }
            $productCategoryEntity->addChanneledProductCategory($channeledProductCategoryEntity);
            $manager->persist($productCategoryEntity);
            $manager->persist($channeledProductCategoryEntity);
            $manager->flush();
        }
        return new Response(json_encode(['Categories processed']));
    }
}