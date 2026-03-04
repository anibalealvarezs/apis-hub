<?php

declare(strict_types=1);

namespace Classes\Requests;

use Classes\Conversions\NetSuiteConvert;
use Classes\Overrides\NetSuiteApi\NetSuiteApi;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductCategory;
use Entities\Analytics\ProductCategory;
use Enums\Channel;
use Repositories\Channeled\ChanneledProductCategoryRepository;
use Repositories\Channeled\ChanneledProductRepository;
use Repositories\ProductCategoryRepository;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductCategoryRequests implements RequestInterface
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
     * @param string|null $publishedAtMin
     * @param string|null $publishedAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromShopify(
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true, ?int $jobId = null): Response {
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
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromKlaviyo(array $fields = null, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
    public static function getListFromNetsuite(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
        /** @var ChanneledProductCategoryRepository $channeledProductCategoryRepository */
        $channeledProductCategoryRepository = $manager->getRepository(entityName: ChanneledProductCategory::class);
        $lastChanneledProductCategory = $channeledProductCategoryRepository->getLastByPlatformId(channel: Channel::netsuite->value);

        $query = "SELECT * FROM CommerceCategory WHERE id >= " . (isset($lastChanneledProductCategory['platformId']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? $lastChanneledProductCategory['platformId'] : 0) . " ORDER BY id ASC";
        $netsuiteClient->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function($productCategories) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                self::process(NetSuiteConvert::productCategories($productCategories));
            }
        );
        return new Response(json_encode(['Product Categories retrieved.']));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param array|null $collects
     * @return Response
     * @throws ORMException
     */
    public static function process(ArrayCollection $channeledCollection, ?array $collects = null): Response
    {
        try {
            $manager = Helpers::getManager();
            
            $result = \Classes\ProductCategoryProcessor::processCategories($channeledCollection, $collects, $manager);

            if (!empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $entities = [
                    'ProductCategory' => $result['productCategories'],
                    'ChanneledProductCategory' => $result['channeledProductCategories'],
                    'ChanneledProduct' => $result['channeledProducts'],
                ];
                
                $channelName = Channel::from(reset($result['channels']))->getName(); 
                
                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn($value) => !empty($value)),
                    channel: $channelName
                );
            }

            return new Response(content: json_encode(value: ['Categories processed']));
        } catch (Exception $e) {
            return new Response(
                content: json_encode(value: ['error' => $e->getMessage()]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
