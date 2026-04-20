<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class PageRequests implements RequestInterface
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
        $chanEnum = ($channel instanceof Channel) ? $channel : Channel::tryFromName((string)$channel);
        $chanKey = $chanEnum?->name ?? (string)$channel;

        return (new \Core\Services\SyncService($logger))->execute($chanKey, $startDate, $endDate, [
            'jobId' => $jobId,
            'filters' => $filters,
            'entity' => 'pages',
        ]);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        $manager = Helpers::getManager();
        \Classes\SocialProcessor::processPages($channeledCollection, $manager);

        return new Response(json_encode(['Pages processed']));
    }
}
