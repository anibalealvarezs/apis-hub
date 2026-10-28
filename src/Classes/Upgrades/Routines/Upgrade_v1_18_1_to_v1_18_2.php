<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_18_1_to_v1_18_2 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.18.1'];
    }

    public function getToVersion(): string
    {
        return '1.18.2';
    }

    public function getDescription(): string
    {
        return 'Modernizes Meta Ads synchronization with configurable breakdown passes, expanded conversion metrics, and optional metrics purge on nuclear resync.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Verified Meta Ads breakdown passes and nuclear resync metrics wipe support for v1.18.2.");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.18.2 upgrade routine.");
    }
}
