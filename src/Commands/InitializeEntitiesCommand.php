<?php

namespace Commands;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Page; // Add Page entity
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
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
        $this->logger = new Logger('initialize-entities');
        $this->logger->pushHandler(new StreamHandler('logs/initialize-entities.log', Level::Info));
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Starting app:initialize-entities command');

        try {
            // Initialize Countries
            $countryRepository = $this->entityManager->getRepository(Country::class);
            $countriesInitialized = 0;
            $countriesSkipped = 0;

            foreach (CountryEnum::cases() as $countryEnum) {
                $country = $countryRepository->findOneBy(['code' => $countryEnum]);
                if (!$country) {
                    $country = new Country();
                    $country->addCode($countryEnum)
                        ->addName($countryEnum->getFullName());
                    $this->entityManager->persist($country);
                    $countriesInitialized++;
                    $this->logger->info("Initialized Country: {$countryEnum->value} ({$countryEnum->getFullName()})");
                } else {
                    $countriesSkipped++;
                    $this->logger->info("Skipped Country: {$countryEnum->value} (already exists)");
                }
            }

            // Initialize Devices
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
                $device = $deviceRepository->findOneBy(['type' => $data['type']]);
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
            $pageRepository = $this->entityManager->getRepository(Page::class);
            $scConfig = Helpers::getChannelsConfig()['google_search_console'] ?? null;
            if (!$scConfig) {
                throw new Exception("Missing 'google_search_console' configuration in channels config");
            }
            $pagesInitialized = 0;
            $pagesSkipped = 0;

            foreach ($scConfig['sites'] as $site) {
                $siteUrl = $site['url'];
                $normalizedSiteUrl = rtrim($siteUrl, '/');
                $title = $site['title'] ?? $siteUrl;
                $hostname = $site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);

                $page = $pageRepository->findOneBy(['url' => $normalizedSiteUrl]);
                if (!$page) {
                    $page = new Page();
                    $page->addUrl($normalizedSiteUrl)
                        ->addTitle($title)
                        ->addHostname($hostname)
                        ->addData(['source' => 'gsc_site']);
                    $this->entityManager->persist($page);
                    $pagesInitialized++;
                    $this->logger->info("Initialized Page: URL={$normalizedSiteUrl}, Title={$title}");
                } else {
                    // Update if title or hostname changed
                    if ($page->getTitle() !== $title || $page->getHostname() !== $hostname) {
                        $page->addTitle($title)
                            ->addHostname($hostname)
                            ->addUpdatedAt(new \DateTime());
                        $this->entityManager->persist($page);
                        $pagesInitialized++;
                        $this->logger->info("Updated Page: URL={$normalizedSiteUrl}, Title={$title}");
                    } else {
                        $pagesSkipped++;
                        $this->logger->info("Skipped Page: URL={$normalizedSiteUrl} (already exists)");
                    }
                }
            }

            // Flush changes
            $this->entityManager->flush();
            $this->logger->info("Flushed changes to database");

            // Output results
            $output->writeln("<info>Initialized {$countriesInitialized} countries, skipped {$countriesSkipped}</info>");
            $output->writeln("<info>Initialized {$devicesInitialized} devices, skipped {$devicesSkipped}</info>");
            $output->writeln("<info>Initialized {$pagesInitialized} pages, skipped {$pagesSkipped}</info>");
            $output->writeln("<info>Initialization completed successfully</info>");
            $this->logger->info("Completed app:initialize-entities command");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Error during initialization: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            $output->writeln("<error>Error during initialization: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}