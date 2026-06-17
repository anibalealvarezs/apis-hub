<?php

    declare(strict_types=1);

    namespace Commands;

    use Anibalealvarezs\ApiSkeleton\Enums\Country as CountryEnum;
    use Anibalealvarezs\ApiSkeleton\Enums\Device as DeviceEnum;
    use Doctrine\ORM\EntityManagerInterface;
    use Entities\Analytics\Country;
    use Entities\Analytics\Device;
    use Exception;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Logger\ConsoleLogger;
    use Symfony\Component\Console\Output\OutputInterface;

    #[AsCommand(
        name: 'app:initialize-default-entities',
        description: 'Initializes Core default entities (Countries, Devices) in the database'
    )]
    class InitializeDefaultEntitiesCommand extends Command
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
            $this->logger->info("Starting core entity initialization...");

            try {
                $this->initializeCoreEntities();
                $this->logger->info("Core entity initialization complete.");
                return Command::SUCCESS;
            } catch (Exception $e) {
                $this->logger?->error("Core entity initialization failed: ".$e->getMessage());
                return Command::FAILURE;
            }
        }

        /**
         * @throws \Doctrine\DBAL\Exception
         */
        private function initializeCoreEntities(): void
        {
            $this->logger->info("Initializing Core Entities (Countries, Devices)...");

            $countryRepository = $this->entityManager->getRepository(Country::class);
            $added = 0;
            foreach (CountryEnum::cases() as $countryEnum) {
                $country = $countryRepository->getByCode($countryEnum->value);
                if (!$country) {
                    $country = new Country();
                    $country->addCode($countryEnum)
                        ->addName($countryEnum->getFullName());
                    $this->entityManager->persist($country);
                    $added++;
                }
            }
            $this->entityManager->flush();
            $this->logger->info("  - Countries: $added added.");

            $deviceRepository = $this->entityManager->getRepository(Device::class);
            // Normalize legacy deployments that persisted UNKNOWN instead of enum-backed unknown.
            $normalizedUnknown = $this->entityManager->getConnection()->executeStatement(
                'UPDATE devices SET type = :normalized WHERE LOWER(type) = :unknown AND type <> :normalized',
                [
                    'normalized' => DeviceEnum::UNKNOWN->value,
                    'unknown'    => strtolower(DeviceEnum::UNKNOWN->value),
                ]
            );
            if ($normalizedUnknown > 0) {
                $this->logger->info("  - Devices: normalized $normalizedUnknown legacy UNKNOWN values.");
            }

            $added = 0;
            foreach (DeviceEnum::cases() as $deviceCase) {
                $device = $deviceRepository->getByType($deviceCase->value);
                if (!$device) {
                    $device = new Device();
                    $device->addType($deviceCase);
                    $this->entityManager->persist($device);
                    $added++;
                }
            }

            $this->entityManager->flush();
            $this->logger->info("  - Devices: $added added.");
            $this->logger->info("Core entities initialization complete.");
        }
    }
