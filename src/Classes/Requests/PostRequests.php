<?php

declare(strict_types=1);

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\Conversions\FacebookOrganicConvert;
use Classes\SocialProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Entities\Analytics\Page;
use Entities\Analytics\Channeled\ChanneledAccount;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class PostRequests implements RequestInterface
{
    /**
     * @return Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::facebook_organic,
        ];
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @param array|null $pageIds
     * @return Response
     */
    public static function getListFromFacebookOrganic(
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?array $pageIds = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger, 'facebook_organic');
            
            // Apply default dates if missing - Respecting cache_history_range from config
            if (empty($startDate)) {
                $days = 30; // Absolute fallback
                $window = $config['cache_history_range'] ?? null;
                if (!empty($window)) {
                    try {
                        // Handle both ISO intervals (P3Y) and human readable (3 years)
                        $intervalStr = (str_starts_with((string)$window, 'P')) ? $window : Helpers::humanToIsoInterval($window);
                        $interval = new \DateInterval($intervalStr);
                        $startDate = Carbon::today()->sub($interval)->format('Y-m-d');
                        $logger->info("Using cache_history_range from config: " . $window . " -> $startDate");
                    } catch (\Exception $e) {
                        $logger->warning("Invalid cache_history_range in config: " . $window . ". Using default 30 days. Error: " . $e->getMessage());
                        $startDate = Carbon::today()->subDays($days)->format('Y-m-d');
                    }
                } else {
                    $startDate = Carbon::today()->subDays($days)->format('Y-m-d');
                    $logger->info("No startDate or cache_history_range provided, defaulting to $startDate");
                }
            }

            if (empty($endDate)) {
                $endDate = Carbon::today()->format('Y-m-d');
            }

            $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            $manager = Helpers::getManager();
            $pageRepo = $manager->getRepository(Page::class);
            $channeledAccountRepo = $manager->getRepository(ChanneledAccount::class);

            $pagesToProcess = $config['pages'] ?? [];
            if ($pageIds) {
                $pagesToProcess = array_filter($pagesToProcess, fn($p) => in_array($p['id'], $pageIds));
            }

            $totalPosts = 0;
            $cacheChunkSize = $config['cache_chunk_size'] ?? '1 month';
            $chunks = Helpers::getDateChunks($startDate, $endDate, $cacheChunkSize);

            foreach ($pagesToProcess as $pageCfg) {
                try {
                    Helpers::checkJobStatus($jobId);

                    if (empty($pageCfg['enabled']) || (empty($pageCfg['posts']) && empty($pageCfg['ig_account_media']))) {
                        $logger->info("Skipping posts/media sync for page: " . ($pageCfg['title'] ?? $pageCfg['id']) . " (disabled in config)");
                        continue;
                    }

                    $pageEntity = $pageRepo->findOneBy(['platformId' => $pageCfg['id']]);
                    if (!$pageEntity) {
                        $logger->warning("Page entity not found for platformId: " . $pageCfg['id']);
                        continue;
                    }

                    $accountEntity = $pageEntity->getAccount();

                    foreach ($chunks as $chunk) {
                        Helpers::checkJobStatus($jobId);
                        $cStart = $chunk['start'];
                        $cEnd = $chunk['end'];

                        // 1. Fetch Facebook Page Posts
                        if (!empty($pageCfg['posts'])) {
                            $logger->info("Fetching Facebook posts for page: " . ($pageCfg['title'] ?? $pageCfg['id']) . " since $cStart until $cEnd");

                            $additionalParams = [
                                'since' => $cStart,
                                'until' => $cEnd . (strlen($cEnd) === 10 ? ' 23:59:59' : '')
                            ];

                            // Set pageId in API instance to allow correct token resolution
                            $api->setPageId((string)$pageCfg['id']);
                            
                            $maxRetries = 3;
                            $retryCount = 0;
                            $fetched = false;

                            while ($retryCount < $maxRetries && !$fetched) {
                                try {
                                    $fbPosts = $api->getFacebookPosts(
                                        pageId: (string)$pageCfg['id'],
                                        limit: 100,
                                        additionalParams: $additionalParams
                                    );
                                    if (!empty($fbPosts['data'])) {
                                        $cacheInclude = MetricRequests::getFacebookFilter($config, 'POST', 'cache_include');
                                        $cacheExclude = MetricRequests::getFacebookFilter($config, 'POST', 'cache_exclude');

                                        $data = $fbPosts['data'];
                                        if ($cacheInclude || $cacheExclude) {
                                            $data = array_filter($data, function ($p) use ($cacheInclude, $cacheExclude) {
                                                return Helpers::matchesFilter((string) ($p['message'] ?? $p['story'] ?? ''), $cacheInclude, $cacheExclude) ||
                                                    Helpers::matchesFilter((string) ($p['id'] ?? ''), $cacheInclude, $cacheExclude);
                                            });
                                        }
                                        $logger->info("Retrieved " . count($fbPosts['data']) . " Facebook posts, kept " . count($data) . " after filtering for page chunk: " . ($pageCfg['title'] ?? $pageCfg['id']));

                                        if (!empty($data)) {
                                            $totalPosts += count($data);
                                            $converted = FacebookOrganicConvert::posts(
                                                posts: $data,
                                                pageId: $pageEntity->getId(),
                                                accountId: $accountEntity->getId()
                                            );
                                            self::process($converted);
                                        }
                                    } else {
                                        $logger->info("No Facebook posts found for page chunk: " . ($pageCfg['title'] ?? $pageCfg['id']));
                                    }
                                    $fetched = true;
                                } catch (\Exception $e) {
                                    $msg = $e->getMessage();
                                    $isFatal = (stripos($msg, '(#100)') !== false || stripos($msg, 'Unsupported get request') !== false || stripos($msg, 'missing permissions') !== false || stripos($msg, 'Object with ID') !== false);
                                    
                                    $retryCount++;
                                    if ($retryCount >= $maxRetries || $isFatal) {
                                        $logger->error(($isFatal ? "FATAL PERMISSION ERROR" : "Failed") . " to fetch Facebook posts for page chunk " . $pageCfg['id'] . ": " . $msg);
                                        $fetched = true; // Stop loop
                                        if (!$isFatal) throw $e;
                                        break;
                                    }
                                    $logger->warning("Retry $retryCount/$maxRetries for Facebook posts page chunk " . $pageCfg['id'] . ": " . $msg);
                                    usleep(200000 * $retryCount);
                                }
                            }
                        }

                        // 2. Fetch Instagram Media if applicable
                        if (!empty($pageCfg['ig_account']) && !empty($pageCfg['ig_accounts']) && !empty($pageCfg['ig_account_media'])) {
                            $logger->info("Fetching Instagram media for page: " . ($pageCfg['title'] ?? $pageCfg['id']) . " since $cStart until $cEnd");

                            $channeledAccount = $channeledAccountRepo->findOneBy([
                                'channel' => Channel::facebook_organic->value,
                                'account' => $accountEntity->getId(),
                            ]);

                            $additionalParams = [
                                'since' => $cStart,
                                'until' => $cEnd . (strlen($cEnd) === 10 ? ' 23:59:59' : '')
                            ];

                            $maxRetries = 3;
                            $retryCount = 0;
                            $fetched = false;

                            while ($retryCount < $maxRetries && !$fetched) {
                                try {
                                    $igMedia = $api->getInstagramMedia(
                                        igUserId: (string)$pageCfg['ig_account'],
                                        limit: 100,
                                        additionalParams: $additionalParams
                                    );
                                    if (!empty($igMedia['data'])) {
                                        $cacheInclude = MetricRequests::getFacebookFilter($config, 'IG_MEDIA', 'cache_include');
                                        $cacheExclude = MetricRequests::getFacebookFilter($config, 'IG_MEDIA', 'cache_exclude');

                                        $data = $igMedia['data'];
                                        if ($cacheInclude || $cacheExclude) {
                                            $data = array_filter($data, function ($m) use ($cacheInclude, $cacheExclude) {
                                                return Helpers::matchesFilter((string) ($m['caption'] ?? ''), $cacheInclude, $cacheExclude) ||
                                                    Helpers::matchesFilter((string) ($m['id'] ?? ''), $cacheInclude, $cacheExclude);
                                            });
                                        }
                                        $logger->info("Retrieved " . count($igMedia['data']) . " Instagram media items, kept " . count($data) . " after filtering for page chunk: " . ($pageCfg['title'] ?? $pageCfg['id']));

                                        if (!empty($data)) {
                                            $converted = FacebookOrganicConvert::posts(
                                                posts: $data,
                                                pageId: $pageEntity->getId(),
                                                accountId: $accountEntity->getId(),
                                                channeledAccountId: $channeledAccount ? $channeledAccount->getId() : null
                                            );
                                            self::process($converted);
                                        }
                                    } else {
                                        $logger->info("No Instagram media items found for page chunk: " . ($pageCfg['title'] ?? $pageCfg['id']));
                                    }
                                    $fetched = true;
                                } catch (\Exception $e) {
                                    $msg = $e->getMessage();
                                    $isFatal = (stripos($msg, 'Unsupported get request') !== false || stripos($msg, 'missing permissions') !== false || stripos($msg, 'Object with ID') !== false);
                                    
                                    $retryCount++;
                                    if ($retryCount >= $maxRetries || $isFatal) {
                                        $logger->error(($isFatal ? "FATAL PERMISSION ERROR" : "Failed") . " to fetch Instagram media for page chunk " . $pageCfg['id'] . ": " . $msg);
                                        $fetched = true; // Stop loop
                                        if (!$isFatal) throw $e;
                                        break;
                                    }
                                    $logger->warning("Retry $retryCount/$maxRetries for Instagram media page chunk " . $pageCfg['id'] . ": " . $msg);
                                    usleep(200000 * $retryCount);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $logger->error("Error processing page " . ($pageCfg['title'] ?? $pageCfg['id']) . ": " . $e->getMessage());
                    // Continue to next page
                }
            }

            return new Response(json_encode(['Posts synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in PostRequests::getListFromFacebookOrganic: " . $e->getMessage());
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
        SocialProcessor::processPosts($channeledCollection, $manager);
        return new Response(json_encode(['Posts processed']));
    }
}
