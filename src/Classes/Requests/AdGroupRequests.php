<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Conversions\FacebookConvert;
use Classes\MarketingProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class AdGroupRequests implements RequestInterface
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
                    continue;
                }

                $adsets = $api->getAdsets(adAccountId: $adAccountId);
                $logger->info("Fetched " . count($adsets['data']) . " adsets for ad account $adAccountId");

                if (!empty($adsets['data'])) {
                    self::process(FacebookConvert::adsets($adsets['data'], $channeledAccount->getId()));
                }
            }

            return new Response(json_encode(['AdGroups synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in AdGroupRequests::getListFromFacebook: " . $e->getMessage());
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
        \Classes\MarketingProcessor::processAdGroups($channeledCollection, $manager);
        return new Response(json_encode(['AdGroups processed']));
    }
}
