<?php

namespace Channels\BigCommerce;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use DateTime;
use Exception;

class BigCommerceDriver implements SyncDriverInterface
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
        return 'bigcommerce';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->logger) {
            $this->logger = Helpers::setLogger('bigcommerce-driver.log');
        }

        $this->logger->info("BigCommerceDriver: No native implementation yet. Sync skipped.");
        
        return new Response(json_encode([
            'status' => 'skipped',
            'message' => 'BigCommerce driver placeholder executed successfully.'
        ]));
    }
}
