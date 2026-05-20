<?php

    declare(strict_types=1);

    namespace Classes\Requests;

    use Core\Services\SyncService;
    use Doctrine\Common\Collections\ArrayCollection;
    use Interfaces\RequestInterface;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\HttpFoundation\Response;
    use Throwable;

    class DiscountRequests implements RequestInterface
    {
        /**
         * @param object|string $channel
         * @param string|null $startDate
         * @param string|null $endDate
         * @param LoggerInterface|null $logger
         * @param int|null $jobId
         * @param object|null $filters
         * @return Response
         * @throws Throwable
         */
        public static function getList(
            object|string    $channel,
            ?string          $startDate = null,
            ?string          $endDate = null,
            ?LoggerInterface $logger = null,
            ?int             $jobId = null,
            ?object          $filters = null
        ): Response
        {
            $syncService = new SyncService();

            return $syncService->execute($channel, $startDate, $endDate, [
                'jobId'   => $jobId,
                'resume'  => $filters->resume ?? true,
                'type'    => 'discounts',
                'filters' => $filters,
            ]);
        }

        /**
         * @param ArrayCollection $channeledCollection
         * @param LoggerInterface|null $logger
         * @return Response
         */
        public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
        {
            // TODO: Implement process() method.

            return new Response(json_encode(['Discounts processed']));
        }
    }