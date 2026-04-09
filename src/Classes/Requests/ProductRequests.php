<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Enums\Channel;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
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
        return (new \Core\Services\SyncService())->execute('shopify', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'products',
            'collectionId' => $collectionId,
            'fields' => $fields,
            'filters' => $filters,
        ]);
    }

    /**
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromKlaviyo(
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('klaviyo', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'products',
            'fields' => $fields,
            'filters' => $filters,
        ]);
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
        return (new \Core\Services\SyncService())->execute('bigcommerce', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'products',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetsuite(
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('netsuite', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'products',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('amazon', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'products',
            'filters' => $filters,
        ]);
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

            if (! empty($result)) {
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
                    entities: array_filter($entities, fn ($value) => ! empty($value)),
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
