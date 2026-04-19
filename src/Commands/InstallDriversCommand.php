<?php

declare(strict_types=1);

namespace Commands;

use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Channel;
use Entities\Analytics\Provider;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:install-drivers',
    description: 'Installs and updates Providers and Channels from registered Drivers'
)]
class InstallDriversCommand extends Command
{
    private EntityManagerInterface $entityManager;

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
            
            /** @var Provider $provider */
            $provider = $this->entityManager->getRepository(Provider::class)->findOneBy(['name' => $providerName]);
            if (!$provider) {
                $provider = new Provider();
                $provider->setName($providerName)
                    ->setLabel($providerLabel);
                $this->entityManager->persist($provider);
                $io->note("Created Provider: $providerLabel ($providerName)");
            }

            // Get Channel Info
            $channelSystemName = (new $driverClass)->getChannel();
            $channelLabel = method_exists($driverClass, 'getChannelLabel') ? $driverClass::getChannelLabel() : ucfirst($channelName);
            $channelIcon = method_exists($driverClass, 'getChannelIcon') ? $driverClass::getChannelIcon() : substr($channelLabel, 0, 1);
            $cooldown = method_exists($driverClass, 'getCooldown') ? $driverClass::getCooldown() : (str_contains($channelName, 'facebook') || str_contains($channelName, 'instagram') ? 3600 : 600);

            // Integrity Check: Channel Mismatch
            if ($channelName !== $channelSystemName) {
                $io->error("Channel name mismatch: Registry key '$channelName' does not match Driver identity '$channelSystemName'!");
                continue;
            }

            /** @var Channel $dbChannel */
            $dbChannel = $this->entityManager->getRepository(Channel::class)->findOneBy(['name' => $channelName]);
            if (!$dbChannel) {
                $dbChannel = new Channel();
                $dbChannel->setName($channelName)
                    ->setLabel($channelLabel)
                    ->setIcon($channelIcon)
                    ->setCooldown($cooldown)
                    ->setProvider($provider);
                $this->entityManager->persist($dbChannel);
                $io->note("Created Channel: $channelLabel ($channelName)");
            } else {
                // Update label/icon/provider if changed
                $dbChannel->setLabel($channelLabel)
                    ->setIcon($channelIcon)
                    ->setCooldown($cooldown)
                    ->setProvider($provider);
                $io->text("Verified/Updated Channel: $channelLabel ($channelName)");
            }
            
            $installedCount++;
        }

        $this->entityManager->flush();
        $io->success("Successfully installed/updated $installedCount drivers.");

        return Command::SUCCESS;
    }
}
