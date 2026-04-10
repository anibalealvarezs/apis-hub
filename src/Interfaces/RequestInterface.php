<?php

namespace Interfaces;

use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Symfony\Component\HttpFoundation\Response;

use Psr\Log\LoggerInterface;

interface RequestInterface
{
    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection): Response;

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
    ): \Symfony\Component\HttpFoundation\Response;
}
