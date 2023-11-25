<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
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
use Symfony\Component\HttpFoundation\Response;

class ProductCategoryRequests
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
        $manager = Helpers::getManager();
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
        );
        $sourceSmartCollections = $shopifyClient->getAllSmartCollections(
            fields: $fields,
            ids: $filters->ids ?? null,
            publishedAtMin: $publishedAtMin,
            publishedAtMax: $publishedAtMax,
            sinceId: $filters->sinceId ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
        $sourceCollects = $shopifyClient->getAllCollects();
        $customCollections = ShopifyConvert::productCategories($sourceCustomCollections['custom_collections']);
        $smartCollections = ShopifyConvert::productCategories($sourceSmartCollections['smart_collections'], true);
        $collects = ShopifyConvert::collects($sourceCollects['collects'])->toArray();
        $productCategoriesCollection = new ArrayCollection([...$customCollections->toArray(), ...$smartCollections->toArray()]);
        $productCategoryRepository = $manager->getRepository(ProductCategory::class);
        $channeledProductCategoryRepository = $manager->getRepository(ChanneledProductCategory::class);
        $channeledProductRepository = $manager->getRepository(ChanneledProduct::class);
        foreach ($productCategoriesCollection as $productCategory) {
            if (!$productCategoryEntity = $productCategoryRepository->getByProductCategoryId($productCategory->platformId)) {
                $productCategoryEntity = $productCategoryRepository->create(
                    data: (object) [
                        'productCategoryId' => $productCategory->platformId,
                        'isSmartCollection' => $productCategory->isSmartCollection,
                    ],
                    returnEntity: true,
                );
            }
            if (!$channeledProductCategoryEntity = $channeledProductCategoryRepository->getByPlatformIdAndChannel($productCategory->platformId, $productCategory->channel)) {
                $channeledProductCategoryEntity = $channeledProductCategoryRepository->create(
                    data: $productCategory,
                    returnEntity: true,
                );
            }
            if (empty($channeledProductCategoryEntity->getData())) {
                $channeledProductCategoryEntity
                    ->addPlatformId($productCategory->platformId)
                    ->addIsSmartCollection($productCategory->isSmartCollection)
                    ->addData($productCategory->data);
            }
            if (isset($collects[$productCategory->platformId])) {
                foreach($collects[$productCategory->platformId] as $productId) {
                    if (!$channeledProductEntity = $channeledProductRepository->getByPlatformIdAndChannel($productId, $productCategory->channel)) {
                        $channeledProductEntity = $channeledProductRepository->create(
                            data: (object) [
                                'channel' => $productCategory->channel,
                                'platformId' => $productId,
                                'data' => [],
                            ],
                            returnEntity: true,
                        );
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
        return new Response(json_encode([...$sourceCustomCollections, ...$sourceSmartCollections]));
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
}