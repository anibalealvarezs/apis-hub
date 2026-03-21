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

            $success = true;
            $errors = [];

            // 1. Sync Campaigns
            $campResponse = CampaignRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId);
            if ($campResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "Campaigns sync failed: " . ($campResponse->getContent() ?: "Unknown error");
            }
            $campData = json_decode($campResponse->getContent(), true);
            $authorizedCampaignMap = $campData['authorized_ids_map'] ?? [];

            // 2. Sync AdGroups
            $adGroupResponse = AdGroupRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId, null, null, $authorizedCampaignMap);
            if ($adGroupResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "AdGroups sync failed: " . ($adGroupResponse->getContent() ?: "Unknown error");
            }
            $adGroupData = json_decode($adGroupResponse->getContent(), true);
            $authorizedAdSetMap = $adGroupData['authorized_ids_map'] ?? [];

            // 3. Sync Creatives
            $creativeResponse = CreativeRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId);
            if ($creativeResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "Creatives sync failed: " . ($creativeResponse->getContent() ?: "Unknown error");
            }

            // 4. Sync Ads
            $adResponse = AdRequests::getListFromFacebookMarketing($startDate, $endDate, $logger, $jobId, null, null, $authorizedAdSetMap);
            if ($adResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "Ads sync failed: " . ($adResponse->getContent() ?: "Unknown error");
            }

            $logger->info("Facebook Marketing entities sync completed");

            if (!$success) {
                return new Response(json_encode(['error' => implode('; ', $errors)]), 500);
            }

            return new Response(json_encode(['message' => 'Facebook Marketing entities synchronized']), 200, ['Content-Type' => 'application/json']);
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

            $success = true;
            $errors = [];

            // 1. Sync Pages
            $pageResponse = PageRequests::getListFromFacebookOrganic($startDate, $endDate, $logger, $jobId);
            if ($pageResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "Pages sync failed: " . ($pageResponse->getContent() ?: "Unknown error");
            }

            // 2. Sync Posts
            $postResponse = PostRequests::getListFromFacebookOrganic($startDate, $endDate, $logger, $jobId);
            if ($postResponse->getStatusCode() >= 400) {
                $success = false;
                $errors[] = "Posts sync failed: " . ($postResponse->getContent() ?: "Unknown error");
            }

            $logger->info("Facebook Organic entities sync completed");

            if (!$success) {
                return new Response(json_encode(['error' => implode('; ', $errors)]), 500);
            }

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
