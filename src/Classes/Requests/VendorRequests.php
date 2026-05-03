<?php

declare(strict_types=1);

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Channel;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class VendorRequests implements RequestInterface
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
            'type' => 'vendors',
            'filters' => $filters,
        ]);
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        // TODO: Implement process() method.

        return new Response(json_encode(['Vendors processed']));
    }
}
