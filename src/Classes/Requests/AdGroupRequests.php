<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
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

                $maxRetries = 3;
                $retryCount = 0;
                $fetched = false;

                while ($retryCount < $maxRetries && !$fetched) {
                    try {
                        $additionalParams = [];
                        if ($startDate) {
                            if (!isset($additionalParams['filtering'])) $additionalParams['filtering'] = [];
                            $additionalParams['filtering'][] = [
                                'field' => 'updated_time',
                                'operator' => 'GREATER_THAN',
                                'value' => strtotime($startDate)
                            ];
                        }
                        if ($endDate) {
                            if (!isset($additionalParams['filtering'])) $additionalParams['filtering'] = [];
                            $additionalParams['filtering'][] = [
                                'field' => 'updated_time',
                                'operator' => 'LESS_THAN',
                                'value' => strtotime($endDate)
                            ];
                        }

                        $cacheInclude = MetricRequests::getFacebookFilter($config, 'ADSET', 'cache_include');
                        $cacheExclude = MetricRequests::getFacebookFilter($config, 'ADSET', 'cache_exclude');

                        if ($cacheInclude && !is_array($cacheInclude) && !str_starts_with($cacheInclude, '/')) {
                            if (!isset($additionalParams['filtering'])) $additionalParams['filtering'] = [];
                            $additionalParams['filtering'][] = [
                                'field' => 'name',
                                'operator' => 'CONTAIN',
                                'value' => $cacheInclude
                            ];
                        }

                        $adsets = $api->getAdsets(
                            adAccountId: $adAccountId,
                            additionalParams: $additionalParams
                        );

                        if (!empty($adsets['data'])) {
                            $data = $adsets['data'];
                            if ($cacheInclude || $cacheExclude) {
                                $data = array_filter($data, function ($a) use ($cacheInclude, $cacheExclude) {
                                    return Helpers::matchesFilter((string) ($a['name'] ?? ''), $cacheInclude, $cacheExclude) ||
                                        Helpers::matchesFilter((string) ($a['id'] ?? ''), $cacheInclude, $cacheExclude);
                                });
                            }
                            $logger->info("Fetched " . count($adsets['data']) . " adsets, kept " . count($data) . " after filtering for ad account $adAccountId");

                            if (!empty($data)) {
                                self::process(FacebookMarketingConvert::adsets($data, $channeledAccount->getId()));
                            }
                        } else {
                            $logger->info("Fetched 0 adsets for ad account $adAccountId");
                        }
                        $fetched = true;
                    } catch (\Exception $e) {
                        $retryCount++;
                        if ($retryCount >= $maxRetries) {
                            $hasErrors = true;
                            $logger->error("Error fetching/processing adsets for ad account $adAccountId after $maxRetries retries: " . $e->getMessage());
                            $syncErrorRepo->logError([
                                'platformId' => $adAccountId,
                                'channel' => Channel::facebook_marketing->value,
                                'syncType' => 'entity',
                                'entityType' => 'adset',
                                'errorMessage' => $e->getMessage(),
                                'extraData' => ['jobId' => $jobId]
                            ]);
                        } else {
                            $logger->warning("Retry $retryCount/$maxRetries for adsets sync $adAccountId: " . $e->getMessage());
                            usleep(200000 * $retryCount);
                        }
                    }
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
