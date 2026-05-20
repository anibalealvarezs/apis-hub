<?php

declare(strict_types=1);

namespace Classes\Requests;

use Core\Services\SyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class VendorRequests implements RequestInterface
{
    /**
     * @param object|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public static function getList(
        object|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): Response {
        return (new SyncService())->execute($channel, $startDate, $endDate, [
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