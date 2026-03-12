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
    /**
     * @return Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::facebook,
        ];
    }

    /**
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @return Response
     */
    public static function getListFromFacebook(
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $logger->info("Starting full Facebook entities sync via FacebookEntityRequests");

            // 1. Sync Pages
            PageRequests::getListFromFacebook($logger, $jobId);

            // 2. Sync Campaigns
            CampaignRequests::getListFromFacebook($logger, $jobId);

            // 3. Sync AdGroups
            AdGroupRequests::getListFromFacebook($logger, $jobId);

            // 4. Sync Ads
            AdRequests::getListFromFacebook($logger, $jobId);

            // 5. Sync Posts
            PostRequests::getListFromFacebook($logger, $jobId);

            $logger->info("Full Facebook entities sync completed");

            return new Response(json_encode(['All Facebook entities synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in FacebookEntityRequests::getListFromFacebook: " . $e->getMessage());
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
