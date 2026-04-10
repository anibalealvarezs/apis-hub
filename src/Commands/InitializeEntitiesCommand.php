<?php

namespace Commands;

use Classes\DriverInitializer;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Country;
use Entities\Analytics\Device; // Add Page entity
use Entities\Analytics\Page;
use Enums\Account as AccountEnum;
use Anibalealvarezs\ApiDriverCore\Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use Enums\PageType;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:initialize-entities',
    description: 'Initializes Country, Device, and Page entities in the database'
)]
class InitializeEntitiesCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->logger = Helpers::setLogger('initialize-entities.log');
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Starting app:initialize-entities command');

        try {
            // Initialize Countries
            /** @var \Repositories\CountryRepository $countryRepository */
            $countryRepository = $this->entityManager->getRepository(Country::class);
            $countriesInitialized = 0;
            $countriesSkipped = 0;

            foreach (CountryEnum::cases() as $countryEnum) {
                $country = $countryRepository->getByCode($countryEnum->value);
                if (! $country) {
                    $country = new Country();
                    $country->addCode($countryEnum)
                        ->addName($countryEnum->getFullName());
                    $this->entityManager->persist($country);
                    $countriesInitialized++;
                    $this->logger->info("Initialized Country: $countryEnum->value ({$countryEnum->getFullName()})");
                } else {
                    $countriesSkipped++;
                    $this->logger->info("Skipped Country: $countryEnum->value (already exists)");
                }
            }
            $this->entityManager->flush();
            $this->logger->info("Flushed countries");

            // Initialize Devices
            /** @var \Repositories\DeviceRepository $deviceRepository */
            $deviceRepository = $this->entityManager->getRepository(Device::class);
            $devices = [
                ['type' => DeviceEnum::DESKTOP],
                ['type' => DeviceEnum::MOBILE],
                ['type' => DeviceEnum::TABLET],
                ['type' => DeviceEnum::OTHER],
                ['type' => DeviceEnum::UNKNOWN],
            ];
            $devicesInitialized = 0;
            $devicesSkipped = 0;

            foreach ($devices as $data) {
                $device = $deviceRepository->getByType($data['type']->value);
                if (! $device) {
                    $device = new Device();
                    $device->addType($data['type']);
                    $this->entityManager->persist($device);
                    $devicesInitialized++;
                    $this->logger->info("Initialized Device: {$data['type']->value}");
                } else {
                    $devicesSkipped++;
                    $this->logger->info("Skipped Device: {$data['type']->value} (already exists)");
                }
            }
            $this->entityManager->flush();
            $this->logger->info("Flushed devices");

            // Initialize Pages from google_search_console config
            /** @var \Repositories\PageRepository $pageRepository */
            $pageRepository = $this->entityManager->getRepository(Page::class);
            /** @var \Repositories\Channeled\ChanneledAccountRepository $channeledAccountRepository */
            $channeledAccountRepository = $this->entityManager->getRepository(ChanneledAccount::class);
            $channelsConfig = Helpers::getChannelsConfig();
            $gscConfig = $channelsConfig['google_search_console'] ?? null;
            if (! $gscConfig) {
                $gscConfig = $channelsConfig['gsc'] ?? null;
            }
            if (! $gscConfig) {
                throw new Exception("Missing 'google_search_console' or 'gsc' configuration in channels config");
            }
            $pagesInitialized = 0;
            $pagesSkipped = 0;
            $countriesInitializedCode = 0;
            $countriesSkipped = 0;
            $devicesInitialized = 0;
            $devicesSkipped = 0;
            $countriesInitialized = 0;

            // GSC Section: Only validate and process if explicitly enabled
            $gscEnabled = $channelsConfig['google_search_console']['enabled'] ?? false;
            $sitesToProcess = [];

            if (! $gscEnabled) {
                $this->logger->info("Google Search Console channel is disabled. Skipping entity initialization for GSC.");
            } else {
                $this->logger->info("Initializing Entities for GSC...");

                // Use modular GoogleAuthProvider
                $authProvider = new \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider($channelsConfig['google_search_console']['token_path'] ?? "");
                $sitesToProcess = $channelsConfig['google_search_console']['sites'] ?? [];

                if ($channelsConfig['google_search_console']['cache_all'] ?? false) {
                    $this->logger->info("GSC 'cache_all' enabled. Fetching all sites from API.");

                    try {
                        $apiSites = $this->fetchGscSites($channelsConfig, $authProvider);
                        if (isset($apiSites['siteEntry']) && is_array($apiSites['siteEntry'])) {
                            foreach ($apiSites['siteEntry'] as $apiSite) {
                                $siteUrl = $apiSite['siteUrl'];
                                // Check if already in $sitesToProcess
                                $alreadyInConfig = false;
                                foreach ($sitesToProcess as $s) {
                                    if (rtrim($s['url'], '/') === rtrim($siteUrl, '/')) {
                                        $alreadyInConfig = true;

                                        break;
                                    }
                                }
                                if (! $alreadyInConfig) {
                                    $include = $gscConfig['cache_include'] ?? null;
                                    $exclude = $gscConfig['cache_exclude'] ?? null;
                                    if (Helpers::matchesFilter($siteUrl, $include, $exclude)) {
                                        $sitesToProcess[] = [
                                            'url' => $siteUrl,
                                            'title' => $siteUrl,
                                            'enabled' => true,
                                        ];
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $this->logger->error("Error fetching GSC sites: " . $e->getMessage());
                    }
                }

                foreach ($sitesToProcess as $site) {
                    $siteUrl = $site['url'];
                    $normalizedSiteUrl = rtrim($siteUrl, '/');
                    $title = $site['title'] ?? $siteUrl;
                    $hostname = $site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);

                    $canonicalId = Helpers::getCanonicalPageId($normalizedSiteUrl, null, PageType::WEBSITE);
                    $pageEntity = $pageRepository->getByCanonicalId($canonicalId);
                    if (! $pageEntity) {
                        $pageEntity = new Page();
                        $pageEntity->addCanonicalId($canonicalId);
                        $this->logger->info("Initializing new GSC Page: URL=$normalizedSiteUrl");
                    }

                    $pageEntity->addUrl($normalizedSiteUrl)
                        ->addTitle($title)
                        ->addHostname($hostname)
                        ->addPlatformId(md5($normalizedSiteUrl))
                        ->addData(['source' => 'gsc_site'])
                        ->addUpdatedAt(new DateTime());

                    $this->entityManager->persist($pageEntity);
                    $pagesInitialized++;
                }
                $this->entityManager->flush();
                $this->logger->info("Flushed GSC pages");
            }

            // Initialize Pages from facebook config
            /** @var \Repositories\PageRepository $pageRepository */
            $pageRepository = $this->entityManager->getRepository(Page::class);
            $channelsConfig = Helpers::getChannelsConfig();
            $fbConfig = $channelsConfig['facebook'] ?? [];
            $fbMarketingConfig = $channelsConfig['facebook_marketing'] ?? [];
            $fbOrganicConfig = $channelsConfig['facebook_organic'] ?? [];

            $fbGroupName = $fbConfig['accounts_group_name'] ?? $fbMarketingConfig['accounts_group_name'] ?? $fbOrganicConfig['accounts_group_name'] ?? null;
            if (! $fbGroupName) {
                throw new Exception("Missing 'accounts_group_name' in facebook configurations");
            }

            /** @var \Repositories\AccountRepository $accountRepository */
            $accountRepository = $this->entityManager->getRepository(Account::class);
            /** @var Account|null $accountEntity */
            $accountEntity = $accountRepository->getByName($fbGroupName);
            if (! $accountEntity) {
                $accountEntity = new Account();
                $accountEntity->addName($fbGroupName);
                $this->entityManager->persist($accountEntity);
                $this->logger->info("Initialized Account: Name={$fbGroupName}");
            }
            $this->entityManager->flush();
            $this->logger->info("Flushed Facebook account");

            $fbEnabled = ($fbConfig['enabled'] ?? false) || ($fbMarketingConfig['enabled'] ?? false) || ($fbOrganicConfig['enabled'] ?? false);

            $mergedFbConfig = [];

            if (! $fbEnabled) {
                $this->logger->info("All Meta channels (Organic/Marketing/General) are disabled. Skipping Facebook entity initialization.");
                $pagesToProcess = [];
                $apiPagesMap = [];
            } else {
                $mergedFbConfig = array_merge($fbConfig, $fbMarketingConfig, $fbOrganicConfig);
                $pagesToProcess = $mergedFbConfig['pages'] ?? [];
                $apiPagesMap = [];

                $this->logger->info("Fetching actual pages details from Meta API to enrich configurations...");

                try {
                    $apiPages = $this->fetchFbPages($mergedFbConfig);
                    if (isset($apiPages['data']) && is_array($apiPages['data'])) {
                        foreach ($apiPages['data'] as $apiPage) {
                            $pageId = (string)$apiPage['id'];
                            $apiPagesMap[$pageId] = $apiPage;

                            // Only add newly discovered pages if 'cache_all' is enabled
                            if ($mergedFbConfig['cache_all'] ?? false) {
                                $alreadyInConfig = false;
                                foreach ($pagesToProcess as $p) {
                                    if ((string)$p['id'] === $pageId) {
                                        $alreadyInConfig = true;

                                        break;
                                    }
                                }
                                if (! $alreadyInConfig) {
                                    $pageName = $apiPage['name'] ?? "Page " . $pageId;
                                    $includeFilter = $mergedFbConfig['PAGE']['cache_include'] ?? null;
                                    $excludeFilter = $mergedFbConfig['PAGE']['cache_exclude'] ?? null;
                                    if (Helpers::matchesFilter($pageName, $includeFilter, $excludeFilter) || Helpers::matchesFilter($pageId, $includeFilter, $excludeFilter)) {
                                        $pagesToProcess[] = [
                                            'id' => $pageId,
                                            'url' => "https://www.facebook.com/" . $pageId,
                                            'title' => $pageName,
                                            'hostname' => 'www.facebook.com',
                                            'ig_account' => $apiPage['instagram_business_account']['id'] ?? null,
                                            'access_token' => $apiPage['access_token'] ?? null,
                                            'enabled' => true,
                                            'instagram_business_account' => $apiPage['instagram_business_account'] ?? null,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error("Error fetching Facebook pages: " . $e->getMessage());
                }
            }

            foreach ($pagesToProcess as $page) {
                $platformId = $page['id'];
                // Prioritize API data for title/metadata
                $apiPageData = $apiPagesMap[$platformId] ?? null;
                $title = $apiPageData['name'] ?? $page['title'] ?? "Page " . $platformId;
                $pageUrl = $page['url'] ?? "https://www.facebook.com/" . $platformId;
                if (! str_starts_with($pageUrl, 'http')) {
                    $pageUrl = "https://www.facebook.com/" . $platformId;
                }
                $hostname = $page['hostname'] ?? 'www.facebook.com';

                $canonicalId = Helpers::getCanonicalPageId($pageUrl, $platformId, PageType::FACEBOOK_PAGE);

                // Construct full Page data: Prioritize API data and avoid injecting internal config flags
                $pageData = $apiPageData ?? [];
                $pageData['source'] = 'fb_page';

                // Add specific metadata that might be in config but isn't in main API response
                if (! empty($page['access_token'])) {
                    $pageData['access_token'] = $page['access_token'];
                }
                if (! empty($apiPageData['access_token'])) {
                    $pageData['access_token'] = $apiPageData['access_token'];
                }

                $igId = $page['ig_account'] ?? $apiPageData['instagram_business_account']['id'] ?? null;
                if ($igId) {
                    $pageData['instagram_business_account_id'] = $igId;
                }

                $pageEntity = $pageRepository->getByCanonicalId($canonicalId);
                if (! $pageEntity) {
                    $pageEntity = new Page();
                    $pageEntity->addCanonicalId($canonicalId)
                        ->addPlatformId($platformId)
                        ->addAccount($accountEntity);
                    $this->logger->info("Initializing new Facebook Page: ID=$platformId, Title=$title");
                }

                $pageEntity->addUrl($pageUrl)
                    ->addTitle($title)
                    ->addHostname($hostname)
                    ->addData($pageData)
                    ->addUpdatedAt(new DateTime());

                $this->entityManager->persist($pageEntity);
                $pagesInitialized++;

                // Initialize Instagram Account from facebook config
                if (! empty($page['ig_account'])) {
                    /** @var ChanneledAccount|null $channeledAccountEntity */
                    $channeledAccountEntity = $channeledAccountRepository->getByPlatformId($page['ig_account'], Channel::facebook_organic->value);

                    // Extract IG-specific name and data from raw API data if available
                    $igData = $apiPageData['instagram_business_account'] ?? $page['instagram_business_account'] ?? [];
                    if (empty($igData) && ! empty($page['ig_account'])) {
                        $igData = ['id' => $page['ig_account']];
                    }
                    $igName = $igData['name'] ?? $igData['username'] ?? $title;

                    if (! $channeledAccountEntity) {
                        $channeledAccount = new ChanneledAccount();
                        $channeledAccount->addPlatformId($page['ig_account'])
                            ->addAccount($accountEntity)
                            ->addType(AccountEnum::INSTAGRAM)
                            ->addChannel(Channel::facebook_organic->value)
                            ->addName($igName)
                            ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                            ->addData($igData);
                        $this->entityManager->persist($channeledAccount);
                        $this->logger->info("Initialized Instagram Account: ID={$page['ig_account']}, Name={$igName}");
                    } else {
                        if ($channeledAccountEntity->getName() !== $igName) {
                            $channeledAccountEntity->addName($igName);
                        }
                        $channeledAccountEntity->addData($igData); // Replace with fresh API data
                        $this->entityManager->persist($channeledAccountEntity);
                        $this->logger->info("Updated Instagram Account: ID={$page['ig_account']}, Name={$igName}");
                    }
                }

                // Initialize Facebook Page ChanneledAccount
                /** @var ChanneledAccount|null $fbChanneledAccount */
                $fbChanneledAccount = $channeledAccountRepository->getByPlatformId((string)$platformId, Channel::facebook_organic->value);
                if (! $fbChanneledAccount) {
                    $fbChanneledAccount = new ChanneledAccount();
                    $fbChanneledAccount->addPlatformId((string)$platformId)
                        ->addAccount($accountEntity)
                        ->addType(AccountEnum::FACEBOOK_PAGE)
                        ->addChannel(Channel::facebook_organic->value)
                        ->addName($title)
                        ->addPlatformCreatedAt(new DateTime('2004-02-04'))
                        ->addData($page);
                    $this->entityManager->persist($fbChanneledAccount);
                    $this->logger->info("Initialized FB Page ChanneledAccount: ID=$platformId, Name=$title");
                } else {
                    $fbChanneledAccount->addData(array_merge($fbChanneledAccount->getData() ?? [], $page));
                    $this->entityManager->persist($fbChanneledAccount);
                }
            }
            $this->entityManager->flush();
            $this->logger->info("Flushed Facebook pages and Instagram accounts");
            $adAccountsToProcess = $mergedFbConfig['ad_accounts'] ?? [];
            $apiAdAccsMap = [];

            $this->logger->info("Fetching actual ad accounts details from Meta API...");

            try {
                $apiAdAccs = $this->fetchFbAdAccounts($mergedFbConfig);
                if (isset($apiAdAccs['data']) && is_array($apiAdAccs['data'])) {
                    foreach ($apiAdAccs['data'] as $apiAdAcc) {
                        $adAccId = (string)$apiAdAcc['id'];
                        $apiAdAccsMap[$adAccId] = $apiAdAcc;

                        if ($mergedFbConfig['cache_all'] ?? false) {
                            $alreadyInConfig = false;
                            foreach ($adAccountsToProcess as $aac) {
                                if ((string)$aac['id'] === $adAccId) {
                                    $alreadyInConfig = true;

                                    break;
                                }
                            }
                            if (! $alreadyInConfig) {
                                $adAccName = $apiAdAcc['name'] ?? "Ad Account " . $adAccId;
                                $includeFilter = \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($mergedFbConfig, 'AD_ACCOUNT', 'cache_include');
                                $excludeFilter = \Anibalealvarezs\MetaHubDriver\Services\FacebookEntitySync::getFacebookFilter($mergedFbConfig, 'AD_ACCOUNT', 'cache_exclude');
                                if (Helpers::matchesFilter($adAccName, $includeFilter, $excludeFilter) || Helpers::matchesFilter($adAccId, $includeFilter, $excludeFilter)) {
                                    $adAccountsToProcess[] = [
                                        'id' => $adAccId,
                                        'name' => $adAccName,
                                        'data' => $apiAdAcc,
                                        'enabled' => true,
                                    ];
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error fetching Facebook ad accounts: " . $e->getMessage());
            }

            foreach ($adAccountsToProcess as $adAccount) {
                /** @var ChanneledAccount|null $adAccountEntity */
                $adAccountEntity = $channeledAccountRepository->getByPlatformId($adAccount['id'], Channel::facebook_marketing->value);

                $apiAdAccData = $apiAdAccsMap[$adAccount['id']] ?? null;
                $rawData = array_merge($apiAdAccData ?? [], $adAccount['data'] ?? [], $adAccount);
                $channeledAccountName = $apiAdAccData['name'] ?? $adAccount['name'] ?? $fbGroupName ?? ("Ad Account " . $adAccount['id']);

                if (! $adAccountEntity) {
                    $channeledAccount = new ChanneledAccount();
                    $channeledAccount->addPlatformId($adAccount['id'])
                        ->addAccount($accountEntity)
                        ->addType(AccountEnum::META_AD_ACCOUNT)
                        ->addChannel(Channel::facebook_marketing->value)
                        ->addName($channeledAccountName)
                        ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                        ->addData($rawData);
                    $this->entityManager->persist($channeledAccount);
                    $this->logger->info("Initialized Ad Account: ID={$adAccount['id']}, Name={$channeledAccountName}");
                } else {
                    if ($adAccountEntity->getName() !== $channeledAccountName || empty($adAccountEntity->getName())) {
                        $adAccountEntity->addName($channeledAccountName);
                    }
                    $adAccountEntity->addData($rawData);
                    $this->entityManager->persist($adAccountEntity);
                    $this->logger->info("Updated Ad Account: ID={$adAccount['id']}, Name={$channeledAccountName}");
                }
            }

            // Flush changes
            $this->entityManager->flush();
            $this->logger->info("Flushed changes to database");

            // Output results
            if (Helpers::isDebug()) {
                $output->writeln("<info>Initialized $countriesInitialized countries, skipped $countriesSkipped</info>");
                $output->writeln("<info>Initialized $devicesInitialized devices, skipped $devicesSkipped</info>");
                $output->writeln("<info>Initialized $pagesInitialized pages, skipped $pagesSkipped</info>");
                $output->writeln("<info>Initialization completed successfully</info>");
            }
            $this->logger->info("Completed app:initialize-entities command");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Error during initialization: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            $output->writeln("<error>Error during initialization: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
    }

    protected function fetchGscSites(array $configRaw, \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider $authProvider): array
    {
        $gscConfig = DriverInitializer::validateConfig('google_search_console', $this->logger);
        $gscApi = DriverInitializer::initializeApi('google_search_console', $gscConfig, $this->logger);

        return $gscApi->getSites();
    }

    protected function fetchFbPages(array $fbConfig): array
    {
        $config = DriverInitializer::validateConfig('facebook_marketing', $this->logger);
        $fbApi = DriverInitializer::initializeApi('facebook_marketing', $config, $this->logger);

        return $fbApi->getMyPages(fields: 'id,name,username,access_token,instagram_business_account{id,name,username}');
    }

    protected function fetchFbAdAccounts(array $fbConfig): array
    {
        $config = DriverInitializer::validateConfig('facebook_marketing', $this->logger);
        $fbApi = DriverInitializer::initializeApi('facebook_marketing', $config, $this->logger);

        return $fbApi->getMyAdAccounts(fields: 'id,account_id,name,currency,timezone_name');
    }
}
