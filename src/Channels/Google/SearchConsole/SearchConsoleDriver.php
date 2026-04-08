<?php

namespace Channels\Google\SearchConsole;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi;
use Entities\Channel;
use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Classes\Requests\MetricRequests;

class SearchConsoleDriver implements SyncDriverInterface
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
        return 'google_search_console';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for SearchConsoleDriver");
        }

        $this->logger->info("Starting SearchConsoleDriver sync...");
        $manager = Helpers::getManager();

        try {
            $api = $this->initializeApi($config);
            
            // Initialization
            $channeledMetricRepository = $manager->getRepository(\Entities\Analytics\Channeled\ChanneledMetric::class);
            $pageRepository = $manager->getRepository(\Entities\Analytics\Page::class);
            $countryRepository = $manager->getRepository(\Entities\Analytics\Country::class);
            $deviceRepository = $manager->getRepository(\Entities\Analytics\Device::class);
            
            $metricNames = $config['google_search_console']['metrics'] ?? ['clicks', 'impressions', 'ctr', 'position'];

            // Device & Country Maps (Simplified for this snippet)
            $countryMap = $this->getCountryMap($countryRepository);
            $deviceMap = $this->getDeviceMap($deviceRepository);
            $pageMap = $this->getPageMap($pageRepository);

            $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];

            $sitesToProcess = $config['google_search_console']['sites'] ?? [];
            
            foreach ($sitesToProcess as $site) {
                if (!($site['enabled'] ?? true)) continue;

                $result = $this->processSite(
                    $site,
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d'),
                    $api,
                    $manager,
                    $channeledMetricRepository,
                    $pageRepository,
                    $metricNames,
                    $deviceMap,
                    $countryMap,
                    $pageMap
                );
                
                $totalStats['metrics'] += $result['metrics'];
                $totalStats['rows'] += $result['rows'];
                $totalStats['duplicates'] += $result['duplicates'];
            }

            return new Response(json_encode([
                'status' => 'success', 
                'data' => $totalStats
            ]));

        } catch (Exception $e) {
            $this->logger->error("SearchConsoleDriver error: " . $e->getMessage());
            throw $e;
        }
    }

    private function processSite($site, $start, $end, $api, $manager, $channeledMetricRepo, $pageRepo, $metrics, $deviceMap, $countryMap, $pageMap): array
    {
        // ... (Aquí iría la lógica de processGSCSite adaptada)
        // Para no hacer un archivo de 2000 líneas en un paso, invocaré a MetricRequests
        // mientras terminamos de mover las dependencias privadas.
        return MetricRequests::processGSCSite(
            $site, $start, $end, true, $api, $manager, 
            $channeledMetricRepo, $pageRepo, $metrics, null, 
            $this->logger, $deviceMap, $countryMap, $pageMap
        );
    }

    private function getCountryMap($repo): array
    {
        $countries = $repo->findAll();
        $countryMap = ['map' => [], 'mapReverse' => []];
        foreach ($countries as $country) {
            $countryMap['map'][$country->getCode()->value] = $country;
            $countryMap['mapReverse'][$country->getId()] = $country;
        }
        return $countryMap;
    }

    private function getDeviceMap($repo): array
    {
        $devices = $repo->findAll();
        $deviceMap = ['map' => [], 'mapReverse' => []];
        foreach ($devices as $device) {
            $deviceMap['map'][$device->getType()->value] = $device;
            $deviceMap['mapReverse'][$device->getId()] = $device;
        }
        return $deviceMap;
    }

    private function getPageMap($repo): array
    {
        $pages = $repo->findAll();
        $pageMap = ['map' => [], 'mapReverse' => []];
        foreach ($pages as $page) {
            $pageMap['map'][$page->getUrl()] = $page;
            $pageMap['mapReverse'][$page->getId()] = $page;
        }
        return $pageMap;
    }

    private function initializeApi(array $config): SearchConsoleApi
    {
        return new SearchConsoleApi(
            redirectUrl: $config['google_search_console']['redirect_uri'] ?? '',
            clientId: $_ENV['GOOGLE_CLIENT_ID'] ?? '',
            clientSecret: $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
            refreshToken: $config['google_search_console']['refresh_token'] ?? null,
            userId: $config['google_search_console']['user_id'] ?? null,
            scopes: $this->authProvider->getScopes(),
            token: $this->authProvider->getAccessToken(),
            tokenPath: $config['google_search_console']['token_path'] ?? ""
        );
    }
}
