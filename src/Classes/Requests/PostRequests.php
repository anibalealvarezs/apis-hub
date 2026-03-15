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

        // Apply default dates if missing for "recent" safety
        if (empty($startDate)) {
            $startDate = Carbon::today()->subDays(30)->format('Y-m-d');
            $logger->info("No startDate provided, defaulting to $startDate");
        }
        if (empty($endDate)) {
            $endDate = Carbon::today()->format('Y-m-d');
            $logger->info("No endDate provided, defaulting to $endDate");
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger);
            $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            $manager = Helpers::getManager();
            $pageRepo = $manager->getRepository(Page::class);
            $channeledAccountRepo = $manager->getRepository(ChanneledAccount::class);

            $pagesToProcess = $config['facebook']['pages'] ?? [];
            if ($pageIds) {
                $pagesToProcess = array_filter($pagesToProcess, fn($p) => in_array($p['id'], $pageIds));
            }

            foreach ($pagesToProcess as $pageCfg) {
                try {
                    Helpers::checkJobStatus($jobId);

                    if (empty($pageCfg['enabled']) || empty($pageCfg['posts'])) {
                        $logger->info("Skipping posts sync for page: " . ($pageCfg['title'] ?? $pageCfg['id']) . " (disabled in config)");
                        continue;
                    }

                    $pageEntity = $pageRepo->findOneBy(['platformId' => $pageCfg['id']]);
                    if (!$pageEntity) {
                        $logger->warning("Page entity not found for platformId: " . $pageCfg['id']);
                        continue;
                    }

                    $accountEntity = $pageEntity->getAccount();

                    // 1. Fetch Facebook Page Posts
                    $logger->info("Fetching Facebook posts for page: " . ($pageCfg['title'] ?? $pageCfg['id']) . ($startDate ? " since $startDate" : "") . ($endDate ? " until $endDate" : ""));

                    $additionalParams = [];
                    if ($startDate) {
                        $additionalParams['since'] = $startDate;
                    }
                    if ($endDate) {
                        $additionalParams['until'] = $endDate;
                    }

                    // Set pageId in API instance to allow correct token resolution
                    $api->setPageId((string)$pageCfg['id']);

                    $fbPosts = $api->getFacebookPosts(
                        pageId: (string)$pageCfg['id'],
                        limit: 100,
                        additionalParams: $additionalParams
                    );
                    if (!empty($fbPosts['data'])) {
                        $logger->info("Retrieved " . count($fbPosts['data']) . " Facebook posts for page: " . ($pageCfg['title'] ?? $pageCfg['id']));
                        $converted = FacebookOrganicConvert::posts(
                            posts: $fbPosts['data'],
                            pageId: $pageEntity->getId(),
                            accountId: $accountEntity->getId()
                        );
                        self::process($converted);
                    } else {
                        $logger->info("No Facebook posts found for page: " . ($pageCfg['title'] ?? $pageCfg['id']));
                    }

                    // 2. Fetch Instagram Media if applicable
                    if (!empty($pageCfg['ig_account']) && !empty($pageCfg['ig_account_media'])) {
                        $logger->info("Fetching Instagram media for page: " . ($pageCfg['title'] ?? $pageCfg['id']));

                        // We need a channeled account for IG media as per existing logic
                        // The config might specify which an account to use, or we try to find one linked to the same account
                        $channeledAccount = $channeledAccountRepo->findOneBy([
                            'channel' => Channel::facebook_organic->value,
                            'account' => $accountEntity->getId(),
                        ]);

                        $igMedia = $api->getInstagramMedia(
                            igUserId: (string)$pageCfg['ig_account'],
                            limit: 100,
                            additionalParams: $additionalParams
                        );
                        if (!empty($igMedia['data'])) {
                            $logger->info("Retrieved " . count($igMedia['data']) . " Instagram media items for page: " . ($pageCfg['title'] ?? $pageCfg['id']));
                            $converted = FacebookOrganicConvert::posts(
                                posts: $igMedia['data'],
                                pageId: $pageEntity->getId(),
                                accountId: $accountEntity->getId(),
                                channeledAccountId: $channeledAccount ? $channeledAccount->getId() : null
                            );
                            self::process($converted);
                        } else {
                            $logger->info("No Instagram media items found for page: " . ($pageCfg['title'] ?? $pageCfg['id']));
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
