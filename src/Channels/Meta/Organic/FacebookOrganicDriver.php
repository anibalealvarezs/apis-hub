<?php

namespace Channels\Meta\Organic;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Entities\Channel;
use Carbon\Carbon;
use DateTime;
use Exception;
use Classes\Requests\MetricRequests;
use Classes\Clients\FacebookClient;

class FacebookOrganicDriver implements SyncDriverInterface
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
        return 'facebook_organic';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for FacebookOrganicDriver");
        }

        if (!$this->logger) {
            $this->logger = Helpers::setLogger('facebook-organic-driver.log');
        }

        $this->logger->info("Starting FacebookOrganicDriver sync...");
        
        try {
            // We still need the main config for site lists and other params
            $fullConfig = FacebookClient::getConfig($this->logger, 'facebook_organic');
            
            // Inject token from AuthProvider into the config for the client
            $fullConfig['graph_long_lived_user_access_token'] = $this->authProvider->getAccessToken();
            $fullConfig['user_id'] = ($this->authProvider instanceof \Core\Auth\FacebookAuthProvider) 
                ? $this->authProvider->getUserId() 
                : ($fullConfig['user_id'] ?? '');

            // For now, we delegate the heavy lifting to MetricRequests 
            // while we continue the "strangulation"
            return MetricRequests::getListFromFacebookOrganic(
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $config['resume'] ?? false,
                $this->logger
            );

        } catch (Exception $e) {
            $this->logger->error("FacebookOrganicDriver error: " . $e->getMessage());
            throw $e;
        }
    }
}
