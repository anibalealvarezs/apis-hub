<?php

namespace Commands;

use Classes\Upgrades\UpgradeManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:upgrade-version',
    description: 'Sequentially executes version upgrades, including SQL migrations and schema updates.'
)]
class UpgradeVersionCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption('current-version', 'c', InputOption::VALUE_REQUIRED, 'The current active version of APIs Hub (before the upgrade)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current = $input->getOption('current-version');

        if (!$current) {
            $output->writeln("<error>NO MIGRATION: --current-version is required.</error>");
            return Command::FAILURE;
        }

        $manager = new UpgradeManager($this->entityManager);
        $pathFinder = $manager->getPathFinder();

        // The package automatically resolves its maximum supported version path
        $target = $pathFinder->getMaxUpgradable($current);

        if ($current === $target) {
            $output->writeln("<info>Already at the maximum available version ({$current}). No migrations necessary.</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<info>Initiating Upgrade Sequence</info>");
        $output->writeln("Current Version: <comment>{$current}</comment>");
        $output->writeln("Target Version:  <comment>{$target} (Auto-resolved)</comment>\n");

        $path = $pathFinder->findPath($current, $target);

        if ($path === null || empty($path)) {
            $output->writeln("<error>NO MIGRATION: Could not build a valid upgrade path to {$target}.</error>");
            return Command::FAILURE;
        }

        $output->writeln("Found path containing " . count($path) . " sequence(s). Executing...\n");

        $success = $manager->executeUpgrade($path, $output);

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
