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

    protected function initializeChannelEntities(): void
    {
        $registry = DriverFactory::getRegistry();
        $channelsConfig = Helpers::getChannelsConfig();
        
        foreach ($registry as $channel => $driverCfg) {
            // Determine if specifically enabled for this channel
            $chanConfig = $channelsConfig[$channel] ?? [];
            
            $driverClass = $driverCfg['driver'] ?? null;
            if (!$driverClass || !class_exists($driverClass)) {
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

                $dataProcessor = function ($collection, $logger) {
                    $stats = ['rows' => 0, 'metrics' => 0, 'duplicates' => 0];
                    if ($collection instanceof \Doctrine\Common\Collections\ArrayCollection) {
                        foreach ($collection as $entity) {
                            if ($entity instanceof \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity) {
                                // For now, we manually map UniversalEntity to Doctrine entities in this command
                                // but we could make it more generic.
                                // Actually, for initialization, we mostly deal with Pages and ChanneledAccounts
                                \Classes\SocialProcessor::processUniversalEntity($entity, $this->entityManager);
                                $stats['rows']++;
                            } else {
                                $this->entityManager->persist($entity);
                                $stats['rows']++;
                            }
                        }
                        $this->entityManager->flush();
                    }
                    return $stats;
                };

                /** @var \Entities\Analytics\Channel $channelEntity */
                $channelEntity = $this->entityManager->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channel]);
                if (!$channelEntity) {
                    $this->logger->error("  - ERROR: Channel entity '$channel' NOT FOUND in database. Ensure 'app:install-drivers' ran correctly.");
                    continue; // Skip this channel instead of failing the whole command
                }

                $identityMapper = function (string $type, array $params) use ($channel, $channelEntity) {
                    $repoMap = [
                        'channeled_accounts' => \Entities\Analytics\Channeled\ChanneledAccount::class,
                        'pages' => \Entities\Analytics\Page::class,
                        'accounts' => \Entities\Analytics\Account::class,
                    ];
                    if (!isset($repoMap[$type])) return [];
                    /** @var \Repositories\PageRepository|\Repositories\ChanneledAccountRepository $repo */
                    $repo = $this->entityManager->getRepository($repoMap[$type]);
                    $map = [];
                    if ($type === 'pages') {
                        $lookupField = 'platformId';
                        $searchValues = (array)($params['platform_ids'] ?? []);
                        if (isset($params['canonical_ids'])) {
                            $lookupField = 'canonicalId';
                            $searchValues = (array)$params['canonical_ids'];
                        } elseif (isset($params['urls'])) {
                            $lookupField = 'canonicalId';
                            $urls = (array)$params['urls'];
                            $searchValues = array_map(fn($u) => Helpers::getCanonicalPageId($u, null, 'website'), $urls);
                        }
                        if (!empty($searchValues)) {
                            $entities = $repo->findBy([$lookupField => array_unique($searchValues)]);
                            $getter = 'get'.ucfirst($lookupField);
                            foreach ($entities as $e) $map[(string)$e->$getter()] = $e;
                        }
                    } elseif ($type === 'channeled_accounts' && isset($params['platform_ids'])) {
                        $entities = $repo->findBy(['platformId' => $params['platform_ids'], 'channel' => $channelEntity]);
                        foreach ($entities as $e) $map[(string)$e->getPlatformId()] = $e;
                    } elseif ($type === 'accounts' && isset($params['names'])) {
                        $entities = $repo->findBy(['name' => $params['names']]);
                        foreach ($entities as $e) $map[(string)$e->getName()] = $e;
                    }
                    return $map;
                };

                $results = $driver->initializeEntities(array_merge($chanConfig, [
                    'manager' => $this->entityManager,
                    'identityMapper' => $identityMapper,
                    'dataProcessor' => $dataProcessor
                ]));
                
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
