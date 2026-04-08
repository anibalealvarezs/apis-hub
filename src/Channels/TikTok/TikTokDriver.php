<?php

namespace Channels\TikTok;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use DateTime;

class TikTokDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getChannel(): string
    {
        return 'tiktok';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->logger) {
            $this->logger = Helpers::setLogger('tiktok-driver.log');
        }

        $this->logger->info("TikTokDriver: No native implementation yet. Sync skipped.");
        
        return new Response(json_encode([
            'status' => 'skipped',
            'message' => 'TikTok driver placeholder executed successfully.'
        ]));
    }
}
