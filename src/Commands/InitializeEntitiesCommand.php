<?php

declare(strict_types=1);

namespace Commands;

use Classes\DriverInitializer;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Anibalealvarezs\ApiSkeleton\Enums\Country as CountryEnum;
use Anibalealvarezs\ApiSkeleton\Enums\Device as DeviceEnum;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

#[AsCommand(
    name: 'app:initialize-entities',
    description: 'Initializes Core and Channel-specific entities in the database'
)]
class InitializeEntitiesCommand extends Command
{
    protected EntityManagerInterface $entityManager;
    private ?LoggerInterface $logger = null;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger = new ConsoleLogger($output);
        $this->logger->info("Starting dynamic entity initialization...");

        try {
            // 1. Initialize Core Entities
            $this->initializeCoreEntities();

            // 2. Initialize Channel-specific Entities via Drivers
            $this->initializeChannelEntities();

            $this->logger->info("Initialization complete.");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->logger?->error("Initialization failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function initializeCoreEntities(): void
    {
        $this->logger->info("Initializing Core Entities (Countries, Devices)...");

        // Countries
        /** @var \Repositories\CountryRepository $countryRepository */
        $countryRepository = $this->entityManager->getRepository(Country::class);
        foreach (CountryEnum::cases() as $countryEnum) {
            $country = $countryRepository->getByCode($countryEnum->value);
            if (!$country) {
                $country = new Country();
                $country->addCode($countryEnum)
                    ->addName($countryEnum->getFullName());
                $this->entityManager->persist($country);
            }
        }

        // Devices
        /** @var \Repositories\DeviceRepository $deviceRepository */
        $deviceRepository = $this->entityManager->getRepository(Device::class);
        foreach (DeviceEnum::cases() as $deviceCase) {
            $device = $deviceRepository->getByType($deviceCase->value);
            if (!$device) {
                $device = new Device();
                $device->addType($deviceCase);
                $this->entityManager->persist($device);
            }
        }

        $this->entityManager->flush();
        $this->logger->info("Core entities flushed.");
    }

    protected function initializeChannelEntities(): void
    {
        $registry = DriverFactory::getRegistry();
        $channelsConfig = Helpers::getChannelsConfig();
        
        foreach ($registry as $channel => $driverCfg) {
            // Determine if specifically enabled for this channel
            $chanConfig = $channelsConfig[$channel] ?? [];
            
            $driverClass = $driverCfg['driver'];
            if (!class_exists($driverClass)) {
                $this->logger->error("Driver class '$driverClass' not found for channel: $channel");
                continue;
            }

            // Merge common configurations if specified by the driver
            $commonKey = $driverClass::getCommonConfigKey();
            if ($commonKey && isset($channelsConfig[$commonKey])) {
                $chanConfig = array_replace_recursive($channelsConfig[$commonKey], $chanConfig);
            }

            if (!($chanConfig['enabled'] ?? false)) {
                $this->logger->info("Channel '$channel' is disabled. Skipping.");
                continue;
            }

            $this->logger->info("Initializing entities for channel: $channel...");

            try {
                $driver = DriverFactory::get($channel, $this->logger);
                $results = $driver->initializeEntities($this->entityManager, $chanConfig);
                
                $init = $results['initialized'] ?? 0;
                $skip = $results['skipped'] ?? 0;
                
                $this->logger->info("  - $channel results: $init initialized, $skip skipped.");
            } catch (Exception $e) {
                $this->logger->error("  - Failed to initialize $channel: " . $e->getMessage());
            }
        }
        $this->entityManager->flush();
    }
}
