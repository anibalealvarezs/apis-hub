<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledVendor;
use Entities\Analytics\Product;
use Entities\Analytics\Vendor;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

class ProductRequests
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param int|null $collectionId
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, int $collectionId = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $manager = Helpers::getManager();
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $sourceProducts = $shopifyClient->getAllProducts(
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
            status: $filters->status ?? null,
            title: $filters->title ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            vendor: $filters->vendor ?? null,
        );
        $channeledProductsCollection = ShopifyConvert::products($sourceProducts['products']);
        $productRepository = $manager->getRepository(Product::class);
        $vendorRepository = $manager->getRepository(Vendor::class);
        $channeledProductRepository = $manager->getRepository(ChanneledProduct::class);
        $channeledVendorRepository = $manager->getRepository(ChanneledVendor::class);
        foreach ($channeledProductsCollection as $channeledProduct) {
            if (!$productEntity = $productRepository->getByProductId($channeledProduct->platformId)) {
                $productEntity = $productRepository->create(
                    data: (object) ['productId' => $channeledProduct->platformId,],
                    returnEntity: true,
                );
            }
            if (!$vendorEntity = $vendorRepository->getByName($channeledProduct->vendor)) {
                $vendorEntity = $vendorRepository->create(
                    data: (object) ['name' => $channeledProduct->vendor,],
                    returnEntity: true,
                );
            }
            if (!$channeledVendorEntity = $channeledVendorRepository->getByNameAndChannel($channeledProduct->vendor, $channeledProduct->channel)) {
                $channeledVendorEntity = $channeledVendorRepository->create(
                    data: (object) [
                        'name' => $channeledProduct->vendor,
                        'channel' => $channeledProduct->channel,
                        'platformId' => 0,
                        'data' => [],
                    ],
                    returnEntity: true,
                );
            }
            if (!$channeledProductEntity = $channeledProductRepository->getByPlatformIdAndChannel($channeledProduct->platformId, $channeledProduct->channel)) {
                $channeledProductEntity = $channeledProductRepository->create(
                    data: $channeledProduct,
                    returnEntity: true,
                );
            }
            if (empty($channeledProductEntity->getData())) {
                $channeledProductEntity
                    ->addPlatformId($channeledProduct->platformId)
                    ->addData($channeledProduct->data);
            }
            $channeledProductEntity->addChanneledVendor($channeledVendorEntity);
            $vendorEntity->addChanneledVendor($channeledVendorEntity);
            $productEntity->addChanneledProduct($channeledProductEntity);
            $manager->persist($vendorEntity);
            $manager->persist($productEntity);
            $manager->persist($channeledVendorEntity);
            $manager->persist($channeledProductEntity);
            $manager->flush();
        }
        return new Response(json_encode($sourceProducts));
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