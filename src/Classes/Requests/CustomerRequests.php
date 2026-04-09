<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Enums\Channel;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests implements RequestInterface
{
    /**
     * @return \Enums\Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::klaviyo,
            Channel::facebook_marketing,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
            Channel::instagram,
            Channel::google_analytics,
            Channel::pinterest,
            Channel::linkedin,
            Channel::x,
        ];
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     */
    public static function getListFromShopify(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('shopify', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'fields' => $fields,
            'filters' => $filters,
        ]);
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromKlaviyo(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('klaviyo', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'fields' => $fields,
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromFacebookMarketing(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('facebook_marketing', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
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
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetSuite(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('netsuite', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
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
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromInstagram(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('facebook_organic', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromGoogleAnalytics(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('google_analytics', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromPinterest(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('pinterest', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromLinkedIn(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('linkedin', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    public static function getListFromX(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('x', null, null, [
            'jobId' => $jobId,
            'resume' => $resume,
            'type' => 'customers',
            'filters' => $filters,
        ]);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function process(ArrayCollection $channeledCollection): Response
    {
        try {
            $manager = Helpers::getManager();

            $result = \Classes\CustomerProcessor::processCustomers($channeledCollection, $manager);

            if (! empty($result)) {
                $cacheService = CacheService::getInstance(Helpers::getRedisClient());
                $entities = [
                    'Customer' => $result['emails'],
                    'ChanneledCustomer' => $result['platformIds'],
                ];

                // Taking the first channel processed
                $channelName = Channel::from(reset($result['channels']))->getName();

                $cacheService->invalidateMultipleEntities(
                    array_filter($entities, fn ($value) => ! empty($value)),
                    $channelName
                );
            }

            return new Response(json_encode(['Customers processed']));
        } catch (Exception $e) {
            return new Response(
                json_encode(['error' => $e->getMessage()]),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
