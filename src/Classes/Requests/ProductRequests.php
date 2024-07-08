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
use Entities\Analytics\Channeled\ChanneledOrder;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductVariant;
use Entities\Analytics\Channeled\ChanneledVendor;
use Entities\Analytics\Product;
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
     * @return Response
     * @throws GuzzleException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, int $collectionId = null, array $fields = null, object $filters = null): Response
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
            sinceId: $filters->sinceId ?? $lastChanneledProduct ?: null,
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
        $sourceProducts = $klaviyoClient->getAllCatalogItems(
            catalogItemsFields: $fields,
            filter: $formattedFilters,
        );
        return self::process(KlaviyoConvert::products($sourceProducts['data']));
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
        $vendorRepository = $manager->getRepository(entityName: Vendor::class);
        $channeledProductRepository = $manager->getRepository(entityName: ChanneledProduct::class);
        $channeledProductVariantRepository = $manager->getRepository(entityName: ChanneledProductVariant::class);
        $channeledVendorRepository = $manager->getRepository(entityName: ChanneledVendor::class);
        foreach ($channeledCollection as $channeledProduct) {
            if (!$productRepository->existsByProductId($channeledProduct->platformId)) {
                $productEntity = $productRepository->create(
                    data: (object)['productId' => $channeledProduct->platformId,],
                    returnEntity: true,
                );
            } else {
                $productEntity = $productRepository->getByProductId($channeledProduct->platformId);
            }
            if ($channeledProduct->vendor) {
                if (!$vendorRepository->existsByName($channeledProduct->vendor)) {
                    $vendorEntity = $vendorRepository->create(
                        data: (object)['name' => $channeledProduct->vendor,],
                        returnEntity: true,
                    );
                } else {
                    $vendorEntity = $vendorRepository->getByName($channeledProduct->vendor);
                }
                if (!$channeledVendorRepository->existsByName($channeledProduct->vendor,
                    $channeledProduct->channel)) {
                    $channeledVendorEntity = $channeledVendorRepository->create(
                        data: (object)[
                            'name' => $channeledProduct->vendor,
                            'channel' => $channeledProduct->channel,
                            'platformId' => 0,
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                } else {
                    $channeledVendorEntity = $channeledVendorRepository->getByName($channeledProduct->vendor,
                        $channeledProduct->channel);
                }
            }
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
                    ->addData($channeledProduct->data);
            }
            foreach ($channeledProduct->variants as $productVariant) {
                if (!$productVariantRepository->existsByProductVariantId($productVariant->platformId)) {
                    $productVariantEntity = $productVariantRepository->create(
                        data: (object)['productVariantId' => $productVariant->platformId,],
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
                        ->addData($productVariant->data);
                }
                $productVariantEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $channeledProductEntity->addChanneledProductVariant($channeledProductVariantEntity);
                $manager->persist($productVariantEntity);
                $manager->persist($channeledProductVariantEntity);
                $manager->flush();
            }
            if ($channeledProduct->vendor) {
                $channeledProductEntity->addChanneledVendor($channeledVendorEntity);
                $vendorEntity->addChanneledVendor($channeledVendorEntity);
                $manager->persist($vendorEntity);
                $manager->persist($channeledVendorEntity);
            }
            $productEntity->addChanneledProduct($channeledProductEntity);
            $manager->persist($productEntity);
            $manager->persist($channeledProductEntity);
            $manager->flush();
        }
        return new Response(json_encode(['Products processed']));
    }
}