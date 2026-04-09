<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests implements RequestInterface
{
    
    /**
     * @param \Enums\Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public static function getList(
        \Enums\Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): \Symfony\Component\HttpFoundation\Response {
        $chanEnum = ($channel instanceof \Enums\Channel) ? $channel : \Enums\Channel::tryFromName((string)$channel);
        $chanKey = $chanEnum?->name ?? (string)$channel;

        // Intelligent date resolution
        $start = $startDate;
        $end = $endDate;
        if (in_array($chanKey, ['shopify', 'klaviyo', 'bigcommerce', 'netsuite'])) {
            $start = $filters->createdAtMin ?? $startDate;
            $end = $filters->createdAtMax ?? $endDate;
        }

        return (new \Core\Services\SyncService())->execute($chanKey, $start, $end, [
            'jobId' => $jobId,
            'resume' => $filters->resume ?? true,
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
