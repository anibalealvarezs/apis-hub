<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Conversions\FacebookConvert;
use Classes\SocialProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
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
            Channel::facebook,
        ];
    }

    /**
     * @param LoggerInterface|null $logger
     * @param int|null $jobId
     * @param array|null $pageIds
     * @return Response
     */
    public static function getListFromFacebook(
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?array $pageIds = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger);
            $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            $manager = Helpers::getManager();
            $pageRepo = $manager->getRepository(\Entities\Analytics\Page::class);
            $channeledAccountRepo = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class);

            $pagesToProcess = $config['facebook']['pages'] ?? [];
            if ($pageIds) {
                $pagesToProcess = array_filter($pagesToProcess, fn($p) => in_array($p['id'], $pageIds));
            }

            foreach ($pagesToProcess as $pageCfg) {
                Helpers::checkJobStatus($jobId);

                $pageEntity = $pageRepo->findOneBy(['platformId' => $pageCfg['id']]);
                if (!$pageEntity) {
                    $logger->warning("Page entity not found for platformId: " . $pageCfg['id']);
                    continue;
                }

                $accountEntity = $pageEntity->getAccount();

                // 1. Fetch Facebook Page Posts
                $logger->info("Fetching Facebook posts for page: " . $pageCfg['name']);
                $fbPosts = $api->getFacebookPosts(pageId: (string) $pageCfg['id']);
                if (!empty($fbPosts['data'])) {
                    $converted = FacebookConvert::posts(
                        posts: $fbPosts['data'],
                        pageId: $pageEntity->getId(),
                        accountId: $accountEntity->getId()
                    );
                    self::process($converted);
                }

                // 2. Fetch Instagram Media if applicable
                if (!empty($pageCfg['ig_account'])) {
                    $logger->info("Fetching Instagram media for page: " . $pageCfg['name']);
                    
                    // We need a channeled account for IG media as per existing logic
                    // The config might specify which an account to use, or we try to find one linked to the same account
                    $channeledAccount = $channeledAccountRepo->findOneBy([
                        'channel' => Channel::facebook->value,
                        // This logic might need refinement based on how IG accounts are linked to Ad Accounts
                    ]);

                    $igMedia = $api->getInstagramMedia(igUserId: (string) $pageCfg['ig_account']);
                    if (!empty($igMedia['data'])) {
                        $converted = FacebookConvert::posts(
                            posts: $igMedia['data'],
                            pageId: $pageEntity->getId(),
                            accountId: $accountEntity->getId(),
                            channeledAccountId: $channeledAccount ? $channeledAccount->getId() : null
                        );
                        self::process($converted);
                    }
                }
            }

            return new Response(json_encode(['Posts synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in PostRequests::getListFromFacebook: " . $e->getMessage());
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
        \Classes\SocialProcessor::processPosts($channeledCollection, $manager);
        return new Response(json_encode(['Posts processed']));
    }
}
