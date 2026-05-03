<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Entities\Analytics\Channel;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class PriceRuleRequests implements RequestInterface
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

        $manager = \Helpers\Helpers::getManager();
        $repo = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledPriceRule::class);
        $last = $repo->getLastByPlatformId($chanKey);

        return (new \Core\Services\SyncService())->execute($chanKey, $startDate, $endDate, [
            'jobId' => $jobId,
            'resume' => $filters->resume ?? true,
            'sinceId' => $last['platformId'] ?? null,
            'type' => 'price_rules',
            'filters' => $filters,
            'processor' => [self::class, 'process'],
        ]);
    }

    


    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws ORMException
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        return self::processPriceRules(channeledCollection: $channeledCollection, logger: $logger);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws ORMException
     */
    public static function processPriceRules(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        try {
            $manager = Helpers::getManager();

            $result = \Classes\PriceRuleProcessor::processPriceRules($channeledCollection, $manager);

            if (!empty($result)) {
                $cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
                $channelName = Channel::from(reset($result['channels']))->getName();

                $entities = [
                    'PriceRule' => $result['priceRules'],
                    'ChanneledPriceRule' => $result['channeledPriceRules'],
                    'Discount' => array_unique($result['discountCodes'] ?? []),
                    'ChanneledDiscount' => array_unique($result['channeledDiscountCodes'] ?? []),
                ];

                $cacheService->invalidateMultipleEntities(
                    entities: array_filter($entities, fn ($value) => ! empty($value)),
                    channel: $channelName
                );
            }

            return new Response(content: json_encode(value: ['Discounts processed']));
        } catch (Exception | GuzzleException | \Throwable $e) {
            return new Response(
                content: json_encode(value: ['error' => $e->getMessage()]),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
