<?php

declare(strict_types=1);

namespace Classes\Requests;

use Classes\Conversions\FacebookMarketingConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Channeled\ChanneledSyncError;
use Enums\Channel;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class AdGroupRequests implements RequestInterface
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

            $hasErrors = false;
            $adAccounts = $config['facebook']['ad_accounts'] ?? [];
            if ($adAccountIds) {
                $adAccounts = array_filter($adAccounts, fn($acc) => in_array($acc['id'], $adAccountIds));
            }

            /** @var \Repositories\Channeled\ChanneledSyncErrorRepository $syncErrorRepo */
            $syncErrorRepo = $manager->getRepository(ChanneledSyncError::class);

            foreach ($adAccounts as $adAccount) {
                Helpers::checkJobStatus($jobId);

                if (empty($adAccount['enabled']) || empty($adAccount['adsets'])) {
                    $logger->info("Skipping adsets sync for ad account: " . $adAccount['id'] . " (disabled in config)");
                    continue;
                }

                $adAccountId = (string) $adAccount['id'];

                $channeledAccount = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class)->findOneBy([
                    'platformId' => $adAccountId,
                ]);

                if (!$channeledAccount) {
                    continue;
                }

                try {
                    $additionalParams = [];
                    if ($startDate) $additionalParams['since'] = $startDate;
                    if ($endDate) $additionalParams['until'] = $endDate;

                    $adsets = $api->getAdsets(
                        adAccountId: $adAccountId,
                        additionalParams: $additionalParams
                    );
                    $logger->info("Fetched " . count($adsets['data']) . " adsets for ad account $adAccountId");

                    if (!empty($adsets['data'])) {
                        self::process(FacebookMarketingConvert::adsets($adsets['data'], $channeledAccount->getId()));
                    }
                } catch (\Exception $e) {
                    $hasErrors = true;
                    $logger->error("Error fetching/processing adsets for ad account $adAccountId: " . $e->getMessage());
                    $syncErrorRepo->logError([
                        'platformId' => $adAccountId,
                        'channel' => Channel::facebook_marketing->value,
                        'syncType' => 'entity',
                        'entityType' => 'adset',
                        'errorMessage' => $e->getMessage(),
                        'extraData' => ['jobId' => $jobId]
                    ]);
                }
            }

            if ($hasErrors) {
                throw new \Exception("Finished with partial errors. Check channeled_sync_errors table or logs for details.");
            }

            return new Response(json_encode(['AdGroups synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in AdGroupRequests::getListFromFacebookMarketing initialization: " . $e->getMessage());
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
