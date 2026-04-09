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

class OrderRequests implements RequestInterface
{
    /**
     * @return Channel[]
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
     * @param string|null $processedAtMin
     * @param string|null $processedAtMax
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
        ?string $processedAtMin = null,
        ?string $processedAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('shopify', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'processedAtMin' => $processedAtMin,
            'processedAtMax' => $processedAtMax,
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
    public static function getListFromKlaviyo(?array $fields = null, ?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('klaviyo', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'orders',
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
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('bigcommerce', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'filters' => $filters,
        ]);
    }

    /**
     * @param string $fromDate
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetsuite(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('netsuite', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'orders',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('amazon', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
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

            $result = \Classes\OrderProcessor::processOrders($channeledCollection, $manager);

            if (! empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $entities = [
                    'Order' => $result['orders'],
                    'ChanneledOrder' => $result['channeledOrders'],
                    'ChanneledCustomer' => $result['channeledCustomers'],
                    'ChanneledDiscount' => $result['discounts'],
                    'ChanneledProduct' => $result['channeledProducts'],
                    'ChanneledProductVariant' => $result['channeledVariants'],
                ];

                $channelName = Channel::from(reset($result['channels']))->getName();

                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn ($value) => ! empty($value)),
                    channel: $channelName
                );
            }

            return new Response(content: json_encode(value: ['Orders processed']));
        } catch (Exception $e) {
            return new Response(
                content: json_encode(value: ['error' => $e->getMessage()]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
