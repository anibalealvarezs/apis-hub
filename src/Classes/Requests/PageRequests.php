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

class PageRequests implements RequestInterface
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
     * @return Response
     */
    public static function getListFromFacebookOrganic(
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-entities.log');
        }

        try {
            $config = MetricRequests::validateFacebookConfig($logger);
            $api = MetricRequests::initializeFacebookGraphApi($config, $logger);
            $manager = Helpers::getManager();
            $accountRepo = $manager->getRepository(\Entities\Analytics\Account::class);

            $pagesToProcess = $config['facebook']['pages'] ?? [];
            $logger->info("Processing " . count($pagesToProcess) . " configured Facebook pages");

            $channeledCollection = new ArrayCollection();

            foreach ($pagesToProcess as $pageCfg) {
                Helpers::checkJobStatus($jobId);

                if (empty($pageCfg['enabled'])) {
                    $logger->info("Skipping page sync for page: " . ($pageCfg['name'] ?? $pageCfg['id']) . " (disabled in config)");
                    continue;
                }

                $account = $accountRepo->findOneBy(['name' => $pageCfg['account']]);
                if (!$account) {
                    $logger->warning("Account not found: " . $pageCfg['account']);
                    continue;
                }

                // In this case, 'pages' in config are already "entities" we want to ensure exist in DB
                // But we might want to fetch more info from API if needed
                // For now, let's just use the config data to sync
                $channeledCollection->add(FacebookConvert::pages([$pageCfg], $account->getId())->first());
            }

            if (!$channeledCollection->isEmpty()) {
                self::process($channeledCollection);
            }

            return new Response(json_encode(['Pages synchronized']));
        } catch (\Exception $e) {
            $logger->error("Error in PageRequests::getListFromFacebookOrganic: " . $e->getMessage());
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
        \Classes\SocialProcessor::processPages($channeledCollection, $manager);
        return new Response(json_encode(['Pages processed']));
    }
}
