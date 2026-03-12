<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Conversions\FacebookConvert;
use Classes\MarketingProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class CampaignRequests implements RequestInterface
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
     * @param array|null $adAccountIds
     * @return Response
     */
    public static function getListFromFacebook(
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?array $adAccountIds = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger);
            $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            $manager = Helpers::getManager();

            $accountsToProcess = $adAccountIds ?? array_column($config['facebook']['ad_accounts'], 'id');

            foreach ($accountsToProcess as $adAccountId) {
                Helpers::checkJobStatus($jobId);

                $channeledAccount = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class)->findOneBy([
                    'platformId' => $adAccountId,
                ]);

                if (!$channeledAccount) {
                    $logger->warning("ChanneledAccount not found for platformId: $adAccountId. Skipping campaigns fetch.");
                    continue;
                }

                $campaigns = $api->getCampaigns(adAccountId: $adAccountId);
                $logger->info("Fetched " . count($campaigns['data']) . " campaigns for ad account $adAccountId");

                if (!empty($campaigns['data'])) {
                    self::process(FacebookConvert::campaigns($campaigns['data'], $channeledAccount->getId()));
                }
            }

            return new Response(json_encode(['Campaigns synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in CampaignRequests::getListFromFacebook: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500);
        }
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection): Response
    {
        $manager = Helpers::getManager();
        \Classes\MarketingProcessor::processCampaigns($channeledCollection, $manager);
        return new Response(json_encode(['Campaigns processed']));
    }
}
