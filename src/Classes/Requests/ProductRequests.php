<?php

declare(strict_types=1);

namespace Classes\Requests;

use Classes\Conversions\NetSuiteConvert;
use Anibalealvarezs\KlaviyoApi\KlaviyoApi;
use Anibalealvarezs\NetSuiteApi\NetSuiteApi;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
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
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('shopify', $createdAtMin, $createdAtMax, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'products',
                    'collectionId' => $collectionId,
                    'fields' => $fields,
                    'filters' => $filters,
                ]);
            } catch (\Exception $e) {}
        }

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
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('klaviyo', null, null, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'products',
                    'fields' => $fields,
                    'filters' => $filters,
                ]);
            } catch (\Exception $e) {}
        }

        return new Response(json_encode(['Products retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('bigcommerce', null, null, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'products'
                ]);
            } catch (\Exception $e) {}
        }

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
    public static function getListFromNetsuite(
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('netsuite', null, null, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'products'
                ]);
            } catch (\Exception $e) {}
        }

        return new Response(json_encode(['Products retrieved']));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        if (getenv('USE_MODULAR_DRIVERS')) {
            try {
                return (new \Core\Services\SyncService())->execute('amazon', null, null, [
                    'jobId' => $jobId,
                    'resume' => $resume,
                    'type' => 'products'
                ]);
            } catch (\Exception $e) {}
        }

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
                    entities: array_filter($entities, fn ($value) => !empty($value)),
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
