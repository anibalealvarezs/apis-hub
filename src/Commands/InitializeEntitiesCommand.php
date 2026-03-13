<?php

namespace Commands;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Page; // Add Page entity
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use Enums\Account as AccountEnum;
use Exception;
use Helpers\Helpers;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Classes\Requests\MetricRequests;

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
                if (!$country) {
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
                if (!$device) {
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

            // Initialize Pages from google_search_console config
            /** @var \Repositories\PageRepository $pageRepository */
            $pageRepository = $this->entityManager->getRepository(Page::class);
            /** @var \Repositories\Channeled\ChanneledAccountRepository $channeledAccountRepository */
            $channeledAccountRepository = $this->entityManager->getRepository(ChanneledAccount::class);
            $gscConfigRaw = Helpers::getChannelsConfig();
            $gscConfig = $gscConfigRaw['google_search_console'] ?? null;
            if (!$gscConfig) {
                throw new Exception("Missing 'google_search_console' configuration in channels config");
            }
            $pagesInitialized = 0;
            $pagesSkipped = 0;

            $sitesToProcess = $gscConfig['sites'] ?? [];
            if ($gscConfig['cache_all'] ?? false) {
                $this->logger->info("GSC 'cache_all' enabled. Fetching all sites from API.");
                try {
                    $apiSites = $this->fetchGscSites($gscConfigRaw);
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
                            if (!$alreadyInConfig) {
                                if (Helpers::matchesFilter($siteUrl, $gscConfig['cache_include'] ?? null, $gscConfig['cache_exclude'] ?? null)) {
                                    $sitesToProcess[] = [
                                        'url' => $siteUrl,
                                        'title' => $siteUrl,
                                        'enabled' => true
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

                $pageEntity = $pageRepository->getByUrl($normalizedSiteUrl);
                if (!$pageEntity) {
                    $pageEntity = new Page();
                    $pageEntity->addUrl($normalizedSiteUrl)
                        ->addTitle($title)
                        ->addHostname($hostname)
                        ->addPlatformId(md5($normalizedSiteUrl))
                        ->addData(['source' => 'gsc_site']);
                    $this->entityManager->persist($pageEntity);
                    $pagesInitialized++;
                    $this->logger->info("Initialized Page: URL=$normalizedSiteUrl, Title=$title");
                } else {
                    // Update if title or hostname changed
                    if ($pageEntity->getTitle() !== $title || $pageEntity->getHostname() !== $hostname) {
                        $pageEntity->addTitle($title)
                            ->addHostname($hostname)
                            ->addUpdatedAt(new DateTime());
                        $this->entityManager->persist($pageEntity);
                        $pagesInitialized++;
                        $this->logger->info("Updated Page: URL=$normalizedSiteUrl, Title=$title");
                    } else {
                        $pagesSkipped++;
                        $this->logger->info("Skipped Page: URL=$normalizedSiteUrl (already exists)");
                    }
                }
            }

            // Initialize Pages from facebook config
            /** @var \Repositories\PageRepository $pageRepository */
            $pageRepository = $this->entityManager->getRepository(Page::class);
            $fbConfig = Helpers::getChannelsConfig()['facebook'] ?? null;
            if (!$fbConfig) {
                throw new Exception("Missing 'facebook' configuration in channels config");
            }

            /** @var \Repositories\AccountRepository $accountRepository */
            $accountRepository = $this->entityManager->getRepository(Account::class);
            /** @var Account|null $accountEntity */
            $accountEntity = $accountRepository->getByName($fbConfig['accounts_group_name']);
            if (!$accountEntity) {
                $accountEntity = new Account();
                $accountEntity->addName($fbConfig['accounts_group_name']);
                $this->entityManager->persist($accountEntity);
                $this->logger->info("Initialized Account: Name={$fbConfig['accounts_group_name']}");
            }

            $pagesToProcess = $fbConfig['pages'] ?? [];
            if ($fbConfig['cache_all'] ?? false) {
                $this->logger->info("Facebook 'cache_all' enabled. Fetching all pages from API.");
                try {
                    $apiPages = $this->fetchFbPages($fbConfig);
                    if (isset($apiPages['data']) && is_array($apiPages['data'])) {
                        foreach ($apiPages['data'] as $apiPage) {
                            $pageId = (string)$apiPage['id'];
                            $alreadyInConfig = false;
                            foreach ($pagesToProcess as $p) {
                                if ((string)$p['id'] === $pageId) {
                                    $alreadyInConfig = true;
                                    break;
                                }
                            }
                            if (!$alreadyInConfig) {
                                $pageName = $apiPage['name'] ?? "Page " . $pageId;
                                $includeFilter = MetricRequests::getFacebookFilter(['facebook' => $fbConfig], 'PAGE', 'cache_include');
                                $excludeFilter = MetricRequests::getFacebookFilter(['facebook' => $fbConfig], 'PAGE', 'cache_exclude');
                                if (Helpers::matchesFilter($pageName, $includeFilter, $excludeFilter) || Helpers::matchesFilter($pageId, $includeFilter, $excludeFilter)) {
                                    $pagesToProcess[] = [
                                        'id' => $pageId,
                                        'url' => "https://www.facebook.com/" . $pageId,
                                        'title' => $pageName,
                                        'hostname' => 'www.facebook.com',
                                        'ig_account' => $apiPage['instagram_business_account']['id'] ?? null,
                                        'enabled' => true
                                    ];
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error("Error fetching Facebook pages: " . $e->getMessage());
                }
            }

            foreach ($pagesToProcess as $page) {
                $pageUrl = $page['url'];
                $title = $page['title'];
                $hostname = $page['hostname'];
                $platformId = $page['id'];

                $pageEntity = $pageRepository->getByPlatformId($platformId);
                if (!$pageEntity) {
                    $pageEntity = new Page();
                    $pageEntity->addUrl($pageUrl)
                        ->addTitle($title)
                        ->addHostname($hostname)
                        ->addPlatformId($platformId)
                        ->addAccount($accountEntity)
                        ->addData(['source' => 'fb_page']);
                    $this->entityManager->persist($pageEntity);
                    $pagesInitialized++;
                    $this->logger->info("Initialized Page: ID=$platformId, Title=$title");
                } else {
                    // Update if title or hostname changed
                    if ($pageEntity->getTitle() !== $title || $pageEntity->getHostname() !== $hostname) {
                        $pageEntity->addTitle($title)
                            ->addHostname($hostname)
                            ->addUrl($pageUrl)
                            ->addUpdatedAt(new DateTime());
                        $this->entityManager->persist($pageEntity);
                        $pagesInitialized++;
                        $this->logger->info("Updated Page: ID=$platformId, Title=$title");
                    } else {
                        $pagesSkipped++;
                        $this->logger->info("Skipped Page: ID=$platformId (already exists)");
                    }
                }

                // Initialize Instagram Account from facebook config
                if ($page['ig_account']) {
                    $channeledAccountEntity = $channeledAccountRepository->getByPlatformId($page['ig_account'], Channel::facebook_organic->value);
                    if (!$channeledAccountEntity) {
                        $channeledAccount = new ChanneledAccount();
                        $channeledAccount->addPlatformId($page['ig_account'])
                            ->addAccount($accountEntity)
                            ->addType(AccountEnum::INSTAGRAM)
                            ->addChannel(Channel::facebook_organic->value)
                            ->addName($fbConfig['accounts_group_name'])
                            ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                            ->addData([]);
                        $this->entityManager->persist($channeledAccount);
                        $this->logger->info("Initialized Instagram Account: ID={$page['ig_account']}, Name={$fbConfig['accounts_group_name']}");
                    }
                }
            }
            $adAccountsToProcess = $fbConfig['ad_accounts'] ?? [];
            if ($fbConfig['cache_all'] ?? false) {
                $this->logger->info("Facebook 'cache_all' enabled. Fetching all ad accounts from API.");
                try {
                    $apiAdAccs = $this->fetchFbAdAccounts($fbConfig);
                    if (isset($apiAdAccs['data']) && is_array($apiAdAccs['data'])) {
                        foreach ($apiAdAccs['data'] as $apiAdAcc) {
                            $adAccId = (string)$apiAdAcc['id'];
                            $alreadyInConfig = false;
                            foreach ($adAccountsToProcess as $aac) {
                                if ((string)$aac['id'] === $adAccId) {
                                    $alreadyInConfig = true;
                                    break;
                                }
                            }
                            if (!$alreadyInConfig) {
                                $adAccName = $apiAdAcc['name'] ?? "Ad Account " . $adAccId;
                                $includeFilter = MetricRequests::getFacebookFilter(['facebook' => $fbConfig], 'AD_ACCOUNT', 'cache_include');
                                $excludeFilter = MetricRequests::getFacebookFilter(['facebook' => $fbConfig], 'AD_ACCOUNT', 'cache_exclude');
                                if (Helpers::matchesFilter($adAccName, $includeFilter, $excludeFilter) || Helpers::matchesFilter($adAccId, $includeFilter, $excludeFilter)) {
                                    $adAccountsToProcess[] = [
                                        'id' => $adAccId,
                                        'name' => $adAccName,
                                        'enabled' => true
                                    ];
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error("Error fetching Facebook ad accounts: " . $e->getMessage());
                }
            }

            foreach ($adAccountsToProcess as $adAccount) {
                $adAccountEntity = $channeledAccountRepository->getByPlatformId($adAccount['id'], Channel::facebook_marketing->value);
                if (!$adAccountEntity) {
                    $channeledAccount = new ChanneledAccount();
                    $channeledAccount->addPlatformId($adAccount['id'])
                        ->addAccount($accountEntity)
                        ->addType(AccountEnum::META_AD_ACCOUNT)
                        ->addChannel(Channel::facebook_marketing->value)
                        ->addName($fbConfig['accounts_group_name'])
                        ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                        ->addData([]);
                    $this->entityManager->persist($channeledAccount);
                    $this->logger->info("Initialized Ad Account: ID={$adAccount['id']}, Name={$fbConfig['accounts_group_name']}");
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

    protected function fetchGscSites(array $configRaw): array
    {
        $gscApi = MetricRequests::initializeSearchConsoleApi($configRaw, $this->logger);
        return $gscApi->getSites();
    }

    protected function fetchFbPages(array $fbConfig): array
    {
        $fbApi = MetricRequests::initializeFacebookGraphApi(['facebook' => $fbConfig], $this->logger);
        return $fbApi->getMyPages();
    }

    protected function fetchFbAdAccounts(array $fbConfig): array
    {
        $fbApi = MetricRequests::initializeFacebookGraphApi(['facebook' => $fbConfig], $this->logger);
        return $fbApi->getMyAdAccounts();
    }
}
