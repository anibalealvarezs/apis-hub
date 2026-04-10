<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class PostRequests implements RequestInterface
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
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): \Symfony\Component\HttpFoundation\Response {
        $chanEnum = ($channel instanceof Channel) ? $channel : Channel::tryFromName((string)$channel);
        $chanKey = $chanEnum?->name ?? (string)$channel;

        return (new \Core\Services\SyncService($logger))->execute($chanKey, $startDate, $endDate, [
            'jobId' => $jobId,
            'filters' => $filters,
            'entity' => 'posts',
        ]);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection): Response
    {
        $manager = Helpers::getManager();
        \Classes\SocialProcessor::processPosts($channeledCollection, $manager);

        return new Response(json_encode(['Posts processed']));
    }
}
