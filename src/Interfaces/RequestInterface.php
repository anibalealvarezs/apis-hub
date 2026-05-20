<?php

    namespace Interfaces;

    use Entities\Analytics\Channel;
    use Doctrine\Common\Collections\ArrayCollection;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\HttpFoundation\Response;

    interface RequestInterface
    {
        /**
         * @param ArrayCollection $channeledCollection
         * @param LoggerInterface|null $logger
         * @return Response
         */
        public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response;

        /**
         * @param Channel|string $channel
         * @param string|null $startDate
         * @param string|null $endDate
         * @param LoggerInterface|null $logger
         * @param int|null $jobId
         * @param object|null $filters
         * @return Response
         */
        public static function getList(
            object|string    $channel,
            ?string          $startDate = null,
            ?string          $endDate = null,
            ?LoggerInterface $logger = null,
            ?int             $jobId = null,
            ?object          $filters = null
        ): Response;
    }
