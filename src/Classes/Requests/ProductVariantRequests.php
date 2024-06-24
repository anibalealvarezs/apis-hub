<?php

namespace Classes\Requests;

use Chmw\KlaviyoApi\KlaviyoApi;
use Classes\Conversions\KlaviyoConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductVariant;
use Entities\Analytics\Channeled\ChanneledVendor;
use Entities\Analytics\Product;
use Entities\Analytics\ProductVariant;
use Entities\Analytics\Vendor;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductVariantRequests implements RequestInterface
{
    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromShopify(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode(['Product variants are retrieved along with Products.']));
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
        $sourceVariants = $klaviyoClient->getAllCatalogVariants(
            catalogVariantsFields: $fields,
            filter: $formattedFilters,
        );
        return self::process(KlaviyoConvert::productVariants($sourceVariants['data']));
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
        foreach ($channeledCollection as $channeledVariant) {
            if (!$productRepository->existsByProductId($channeledVariant->platformId)) {
                $productEntity = $productRepository->create(
                    data: (object)['productId' => $channeledVariant->platformId,],
                    returnEntity: true,
                );
            } else {
                $productEntity = $productRepository->getByProductId($channeledVariant->platformId);
            }
            if ($channeledVariant->vendor) {
                if (!$vendorRepository->existsByName($channeledVariant->vendor)) {
                    $vendorEntity = $vendorRepository->create(
                        data: (object)['name' => $channeledVariant->vendor,],
                        returnEntity: true,
                    );
                } else {
                    $vendorEntity = $vendorRepository->getByName($channeledVariant->vendor);
                }
                if (!$channeledVendorRepository->existsByNameAndChannel($channeledVariant->vendor,
                    $channeledVariant->channel)) {
                    $channeledVendorEntity = $channeledVendorRepository->create(
                        data: (object)[
                            'name' => $channeledVariant->vendor,
                            'channel' => $channeledVariant->channel,
                            'platformId' => 0,
                            'data' => [],
                        ],
                        returnEntity: true,
                    );
                } else {
                    $channeledVendorEntity = $channeledVendorRepository->getByNameAndChannel($channeledVariant->vendor,
                        $channeledVariant->channel);
                }
            }
            if (!$channeledProductRepository->existsByPlatformIdAndChannel($channeledVariant->platformId,
                $channeledVariant->channel)) {
                $channeledProductEntity = $channeledProductRepository->create(
                    data: $channeledVariant,
                    returnEntity: true,
                );
            } else {
                $channeledProductEntity = $channeledProductRepository->getByPlatformIdAndChannel($channeledVariant->platformId,
                    $channeledVariant->channel);
            }
            if (empty($channeledProductEntity->getData())) {
                $channeledProductEntity
                    ->addPlatformId($channeledVariant->platformId)
                    ->addData($channeledVariant->data);
            }
            foreach ($channeledVariant->variants as $productVariant) {
                if (!$productVariantRepository->existsByProductVariantId($productVariant->platformId)) {
                    $productVariantEntity = $productVariantRepository->create(
                        data: (object)['productVariantId' => $productVariant->platformId,],
                        returnEntity: true,
                    );
                } else {
                    $productVariantEntity = $productVariantRepository->getByProductVariantId($productVariant->platformId);
                }
                if (!$channeledProductVariantRepository->existsByPlatformIdAndChannel($productVariant->platformId,
                    $channeledVariant->channel)) {
                    $channeledProductVariantEntity = $channeledProductVariantRepository->create(
                        data: $productVariant,
                        returnEntity: true,
                    );
                } else {
                    $channeledProductVariantEntity = $channeledProductVariantRepository->getByPlatformIdAndChannel($productVariant->platformId,
                        $channeledVariant->channel);
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
            if ($channeledVariant->vendor) {
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
        return new Response(json_encode(['Variants processed']));
    }
}