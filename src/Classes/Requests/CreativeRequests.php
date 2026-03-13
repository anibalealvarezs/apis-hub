<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Conversions\FacebookMarketingConvert;
use Classes\MarketingProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class CreativeRequests implements RequestInterface
{
    public static function supportedChannels(): array
    {
        return [
            Channel::facebook_marketing,
        ];
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @param array|null $adAccountIds
     * @return Response
     */
    public static function getListFromFacebookMarketing(
        ?string $startDate = null,
        ?string $endDate = null,
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

            $adAccounts = $config['facebook']['ad_accounts'] ?? [];
            if ($adAccountIds) {
                $adAccounts = array_filter($adAccounts, fn($acc) => in_array($acc['id'], $adAccountIds));
            }

            foreach ($adAccounts as $adAccount) {
                Helpers::checkJobStatus($jobId);

                if (empty($adAccount['enabled']) || empty($adAccount['creatives'])) {
                    $logger->info("Skipping creatives sync for ad account: " . $adAccount['id'] . " (disabled in config)");
                    continue;
                }

                $adAccountId = (string) $adAccount['id'];

                $channeledAccount = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class)->findOneBy([
                    'platformId' => $adAccountId,
                ]);

                if (!$channeledAccount) {
                    continue;
                }

                $additionalParams = [];
                // No date filters for creatives usually, but we keep it for consistency if needed
                // if ($startDate) $additionalParams['since'] = $startDate;
                // if ($endDate) $additionalParams['until'] = $endDate;

                $creatives = $api->getCreatives(
                    adAccountId: $adAccountId,
                    additionalParams: $additionalParams
                );
                $logger->info("Fetched " . count($creatives['data']) . " creatives for ad account $adAccountId");

                if (!empty($creatives['data'])) {
                    self::process(FacebookMarketingConvert::creatives($creatives['data'], $channeledAccount->getId()));
                }
            }

            return new Response(json_encode(['Creatives synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in CreativeRequests::getListFromFacebookMarketing: " . $e->getMessage());
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
        MarketingProcessor::processCreatives($channeledCollection, $manager);
        return new Response(json_encode(['Creatives processed']));
    }
}
