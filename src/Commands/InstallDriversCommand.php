<?php

    declare(strict_types=1);

    namespace Commands;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Enums\InstanceTier;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\Exception\ORMException;
    use Entities\Analytics\Channel;
    use Entities\Analytics\Provider;
    use Exception;
    use Helpers\Helpers;
    use ReflectionClass;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\Yaml\Yaml;
    use Throwable;

    #[AsCommand(
        name: 'app:install-drivers',
        description: 'Installs and updates Providers and Channels from registered Drivers'
    )]
    class InstallDriversCommand extends Command
    {
        private EntityManagerInterface $entityManager;

        /**
         * @throws \Doctrine\DBAL\Exception
         */
        public function __construct()
        {
            parent::__construct();
            $this->entityManager = Helpers::getManager();
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $io = new SymfonyStyle($input, $output);
            $io->title('Installing/Updating Drivers into Database');

            $channels = DriverFactory::getAvailableChannels();
            $installedCount = 0;

            try {
                foreach ($channels as $channelName) {
                    $regConfig = DriverFactory::getChannelConfig($channelName);
                    $driverClass = $regConfig['driver'] ?? null;
                    if (!$driverClass || !class_exists($driverClass)) {
                        $io->warning("Skipping $channelName: Driver class not found.");
                        continue;
                    }

                    // Get Provider Info from Registry OR Driver
                    $providerSystemName = $driverClass::getProviderName();
                    $providerName = $regConfig['parent'] ?? $providerSystemName;
                    $providerLabel = method_exists($driverClass, 'getProviderLabel') ? $driverClass::getProviderLabel() : ucfirst($providerName);

                    // Integrity Check: Provider Mismatch
                    if (isset($regConfig['parent']) && $regConfig['parent'] !== $providerSystemName) {
                        $io->warning("Provider mismatch for $channelName: Registry says '{$regConfig['parent']}', Driver says '$providerSystemName'. Registry takes precedence.");
                    }

                    static $providersCache = [];
                    $provider = $providersCache[$providerName] ?? $this->entityManager->getRepository(Provider::class)->findOneBy(['name' => $providerName]);

                    if (!$provider) {
                        $provider = new Provider();
                        $provider->setName($providerName)
                            ->setLabel($providerLabel);
                        $this->entityManager->persist($provider);
                        $providersCache[$providerName] = $provider;
                        $io->note("Created Provider: $providerLabel ($providerName)");
                    } else {
                        $providersCache[$providerName] = $provider;
                    }

                    $maxWorkers = $this->resolveMaxWorkersForChannel($channelName, $driverClass);
                    $tier = $this->resolveTierForChannel($channelName, $driverClass);

                    // Get Channel Info
                    $channelLabel = method_exists($driverClass, 'getChannelLabel') ? $driverClass::getChannelLabel() : ucfirst($channelName);
                    $channelIcon = method_exists($driverClass, 'getChannelIcon') ? $driverClass::getChannelIcon() : substr($channelLabel, 0, 1);
                    $cooldown = method_exists($driverClass, 'getCooldown') ? $driverClass::getCooldown() : 600;

                    // For validation, we use the static label or just the registry key
                    // Instantiating the driver here is dangerous as it may have dependencies

                    /** @var Channel $dbChannel */
                    $dbChannel = $this->entityManager->getRepository(Channel::class)->findOneBy(['name' => $channelName]);
                    if (!$dbChannel) {
                        $dbChannel = new Channel();
                        $dbChannel->setName($channelName)
                            ->setLabel($channelLabel)
                            ->setIcon($channelIcon)
                            ->setCooldown($cooldown)
                            ->setMaxWorkers($maxWorkers)
                            ->setTier($tier)
                            ->setProvider($provider);
                        $this->entityManager->persist($dbChannel);
                        $io->note("Created Channel: $channelLabel ($channelName) [max_workers=$maxWorkers, tier=$tier]");
                    } else {
                        // Update label/icon/provider if changed
                        $dbChannel->setLabel($channelLabel)
                            ->setIcon($channelIcon)
                            ->setCooldown($cooldown)
                            ->setMaxWorkers($maxWorkers)
                            ->setTier($tier)
                            ->setProvider($provider);
                        $io->text("Verified/Updated Channel: $channelLabel ($channelName) [max_workers=$maxWorkers, tier=$tier]");
                    }

                    $installedCount++;
                }

                $this->entityManager->flush();
                $io->success("Successfully installed/updated $installedCount drivers.");

                return Command::SUCCESS;

            } catch (Exception|ORMException $e) {
                $io->error("Registration failed: ".$e->getMessage());
                $io->text($e->getTraceAsString());

                return Command::FAILURE;
            }
        }

        private function resolveMaxWorkersForChannel(string $channelName, string $driverClass): int
        {
            $config = $this->readChannelConfig($channelName);

            $channelConfig = $config['channels'][$channelName] ?? $config;
            if (array_key_exists('max_workers', $channelConfig)) {
                return max(0, (int)$channelConfig['max_workers']);
            }

            if (method_exists($driverClass, 'getDefaultMaxWorkers')) {
                return max(0, (int)$driverClass::getDefaultMaxWorkers());
            }

            return 3;
        }

        private function resolveTierForChannel(string $channelName, string $driverClass): int
        {
            $config = $this->readChannelConfig($channelName);

            $channelConfig = $config['channels'][$channelName] ?? $config;
            if (array_key_exists('tier', $channelConfig)) {
                return max(0, (int)$channelConfig['tier']);
            }

            try {
                if (is_subclass_of($driverClass, SyncDriverInterface::class)) {
                    $reflection = new ReflectionClass($driverClass);
                    if (!$reflection->isAbstract()) {
                        $instance = new $driverClass();
                        if (method_exists($instance, 'getRequiredInstanceTier')) {
                            $tier = $instance->getRequiredInstanceTier($channelConfig);

                            return $tier->value;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore instantiation errors
            }

            return InstanceTier::BASIC->value;
        }

        private function readChannelConfig(string $channelName): array
        {
            $configDir = Helpers::getConfigDir();
            $paths = [
                $configDir.'/channels/'.$channelName.'.yaml',
                $configDir.'/channels/'.$channelName.'.yml',
            ];

            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    continue;
                }

                $parsed = Yaml::parseFile($path);

                return is_array($parsed) ? $parsed : [];
            }

            return [];
        }
    }
