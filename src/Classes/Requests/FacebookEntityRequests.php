<?php

declare(strict_types=1);

namespace Classes\Requests;

use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class FacebookEntityRequests implements RequestInterface
{
    public static function supportedChannels(): array
    {
        return [
            Channel::facebook_marketing,
            Channel::facebook_organic,
        ];
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @return Response
     */
    public static function getListFromFacebookMarketing(
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-marketing.log');
        }

        try {
            $logger->info("Starting Facebook Marketing entities sync via FacebookEntityRequests");

            // 1. Sync Campaigns
            CampaignRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId);

            // 2. Sync AdGroups
            AdGroupRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId);

            // 3. Sync Ads
            AdRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId);

            $logger->info("Facebook Marketing entities sync completed");

            return new Response(json_encode(['Facebook Marketing entities synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in FacebookEntityRequests::getListFromFacebookMarketing: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500);
        }
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @return Response
     */
    public static function getListFromFacebookOrganic(
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-organic.log');
        }

        try {
            $logger->info("Starting Facebook Organic entities sync via FacebookEntityRequests");

            // 1. Sync Pages
            PageRequests::getListFromFacebookOrganic($startDate, $endDate, $logger, $jobId);

            // 2. Sync Posts
            PostRequests::getListFromFacebookOrganic($startDate, $endDate, $logger, $jobId);

            $logger->info("Facebook Organic entities sync completed");

            return new Response(json_encode(['Facebook Organic entities synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in FacebookEntityRequests::getListFromFacebookOrganic: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500);
        }
    }

    /**
     * @param \Doctrine\Common\Collections\ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(\Doctrine\Common\Collections\ArrayCollection $channeledCollection): Response
    {
        return new Response(json_encode(['Not implemented at this level']));
    }
}
