<?php

declare(strict_types=1);

namespace Classes\Requests;

use Core\Services\SyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Channel;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class EntitySyncRequests implements RequestInterface
{
    /**
     * Handles entity sync jobs (campaigns, ad groups, ads, creatives, etc.).
     * Unlike metric jobs, entity sync jobs have no date range — the driver
     * fetches all currently active entities from the API.
     *
     * @param Channel|string      $channel
     * @param string|null         $startDate
     * @param string|null         $endDate
     * @param LoggerInterface|null $logger
     * @param int|null            $jobId
     * @param object|null         $filters
     * @return Response
     * @throws \Throwable
     */
    public static function getList(
        Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): Response {
        $chanKey = ($channel instanceof Channel) ? $channel->getName() : (string)$channel;

        return (new SyncService($logger))->execute($chanKey, $startDate, $endDate, [
            'jobId'      => $jobId,
            'account_id' => $filters->account_id ?? null,
            'filters'    => $filters,
            'entity'     => 'entities',
        ]);
    }

    /**
     * @param ArrayCollection      $channeledCollection
     * @param LoggerInterface|null $logger
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        // Entity sync jobs produce no direct metric output; persistence is handled
        // by the driver's dataProcessor callback inside SyncService::execute().
        return new Response(json_encode(['status' => 'success', 'message' => 'Entity sync processed.']));
    }
}
