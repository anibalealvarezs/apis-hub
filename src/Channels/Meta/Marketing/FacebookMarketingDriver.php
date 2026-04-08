<?php

namespace Channels\Meta\Marketing;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Entities\Channel;
use DateTime;
use Exception;
use Classes\Requests\MetricRequests;
use Classes\Clients\FacebookClient;

class FacebookMarketingDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function getChannel(): string
    {
        return 'facebook_marketing';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for FacebookMarketingDriver");
        }

        if (!$this->logger) {
            $this->logger = Helpers::setLogger('facebook-marketing-driver.log');
        }

        $this->logger->info("Starting FacebookMarketingDriver sync...");
        
        try {
            // We still need the main config
            $fullConfig = FacebookClient::getConfig($this->logger, 'facebook_marketing');
            
            // Inject token from AuthProvider
            $fullConfig['graph_long_lived_user_access_token'] = $this->authProvider->getAccessToken();
            $fullConfig['user_id'] = ($this->authProvider instanceof \Core\Auth\FacebookAuthProvider) 
                ? $this->authProvider->getUserId() 
                : ($fullConfig['user_id'] ?? '');

            return MetricRequests::getListFromFacebookMarketing(
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $config['resume'] ?? false,
                $this->logger
            );

        } catch (Exception $e) {
            $this->logger->error("FacebookMarketingDriver error: " . $e->getMessage());
            throw $e;
        }
    }
}
