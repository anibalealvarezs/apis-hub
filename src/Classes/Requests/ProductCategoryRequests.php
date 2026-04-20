<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Entities\\Analytics\\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductCategoryRequests implements RequestInterface
{
    


    /**
     * @param Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public static function getList(
        Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): Response {
        $chanKey = ($channel instanceof Channel) ? $channel->name : (string)$channel;

        return (new \Core\Services\SyncService())->execute($chanKey, $startDate, $endDate, [
            'jobId' => $jobId,
            'resume' => $filters->resume ?? true,
            'type' => 'product_categories',
            'filters' => $filters,
        ]);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param array|null $collects
     * @return Response
     * @throws ORMException
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null, ?array $collects = null): Response
    {
        try {
            $manager = Helpers::getManager();

            $result = \Classes\ProductCategoryProcessor::processCategories($channeledCollection, $collects, $manager);

            if (! empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $entities = [
                    'ProductCategory' => $result['productCategories'],
                    'ChanneledProductCategory' => $result['channeledProductCategories'],
                    'ChanneledProduct' => $result['channeledProducts'],
                ];

                $channelName = Channel::from(reset($result['channels']))->getName();

                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn ($value) => ! empty($value)),
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
