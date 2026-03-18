<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Conversions\FacebookMarketingConvert;
use Classes\MarketingProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Channeled\ChanneledSyncError;
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
        ?array $adAccountIds = null,
        ?FacebookGraphApi $api = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger);
            if (!$api) {
                $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            }
            $manager = Helpers::getManager();

            $hasErrors = false;
            $adAccounts = $config['ad_accounts'] ?? [];
            if ($adAccountIds) {
                $adAccounts = array_filter($adAccounts, fn($acc) => in_array($acc['id'], $adAccountIds));
            }

            /** @var \Repositories\Channeled\ChanneledSyncErrorRepository $syncErrorRepo */
            $syncErrorRepo = $manager->getRepository(ChanneledSyncError::class);

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

                $maxRetries = 3;
                $retryCount = 0;
                $fetched = false;

                while ($retryCount < $maxRetries && !$fetched) {
                    try {
                        $additionalParams = [];
                        // No date filters for creatives usually
                        
                        $cacheInclude = MetricRequests::getFacebookFilter($config, 'CREATIVE', 'cache_include');
                        $cacheExclude = MetricRequests::getFacebookFilter($config, 'CREATIVE', 'cache_exclude');

                        if ($cacheInclude && !is_array($cacheInclude) && !str_starts_with($cacheInclude, '/')) {
                            if (!isset($additionalParams['filtering'])) $additionalParams['filtering'] = [];
                            $additionalParams['filtering'][] = [
                                'field' => 'name',
                                'operator' => 'CONTAIN',
                                'value' => $cacheInclude
                            ];
                        }

                        $creatives = $api->getCreatives(
                            adAccountId: $adAccountId,
                            additionalParams: $additionalParams
                        );

                        if (!empty($creatives['data'])) {
                            $data = $creatives['data'];
                            if ($cacheInclude || $cacheExclude) {
                                $data = array_filter($data, function ($c) use ($cacheInclude, $cacheExclude) {
                                    return Helpers::matchesFilter((string) ($c['name'] ?? ''), $cacheInclude, $cacheExclude) ||
                                        Helpers::matchesFilter((string) ($c['id'] ?? ''), $cacheInclude, $cacheExclude);
                                });
                            }
                            $logger->info("Fetched " . count($creatives['data']) . " creatives, kept " . count($data) . " after filtering for ad account $adAccountId");

                            if (!empty($data)) {
                                self::process(FacebookMarketingConvert::creatives($data, $channeledAccount->getId()));
                            }
                        } else {
                            $logger->info("Fetched 0 creatives for ad account $adAccountId");
                        }
                        $fetched = true;
                    } catch (\Exception $e) {
                        $retryCount++;
                        if ($retryCount >= $maxRetries) {
                            $hasErrors = true;
                            $logger->error("Error fetching/processing creatives for ad account $adAccountId after $maxRetries retries: " . $e->getMessage());
                            $syncErrorRepo->logError([
                                'platformId' => $adAccountId,
                                'channel' => Channel::facebook_marketing->value,
                                'syncType' => 'entity',
                                'entityType' => 'creative',
                                'errorMessage' => $e->getMessage(),
                                'extraData' => ['jobId' => $jobId]
                            ]);
                        } else {
                            $logger->warning("Retry $retryCount/$maxRetries for creatives sync $adAccountId: " . $e->getMessage());
                            usleep(200000 * $retryCount);
                        }
                    }
                }
            }

            if ($hasErrors) {
                throw new \Exception("Finished with partial errors. Check channeled_sync_errors table or logs for details.");
            }

            return new Response(json_encode(['Creatives synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in CreativeRequests::getListFromFacebookMarketing initialization: " . $e->getMessage());
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
