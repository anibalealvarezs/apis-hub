<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ProductRequests implements RequestInterface
{
    
    /**
     * @param Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function getList(
        Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): \Symfony\Component\HttpFoundation\Response {
        $chanKey = ($channel instanceof Channel) ? $channel->name : (string)$channel;
        $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chanKey);
        $mapping = $driver->getDateFilterMapping();

        // Intelligent date resolution
        $start = $startDate;
        $end = $endDate;
        if (! empty($mapping)) {
            $start = $filters->{$mapping['start']} ?? $startDate;
            $end = $filters->{$mapping['end']} ?? $endDate;
        }

        return (new \Core\Services\SyncService())->execute($chanKey, $start, $end, [
            'jobId' => $jobId,
            'resume' => $filters->resume ?? true,
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
