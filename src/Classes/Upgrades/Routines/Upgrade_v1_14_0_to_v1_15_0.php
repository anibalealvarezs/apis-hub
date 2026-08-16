<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_14_0_to_v1_15_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.14.0'];
    }

    public function getToVersion(): string
    {
        return '1.15.0';
    }

    public function getDescription(): string
    {
        return 'Adds support for threshold-based alert evaluation engine and alert configuration synchronization.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Synchronizing Doctrine schema for v1.15.0 (Alert Engine Integration)...");
        $tool = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $tool->updateSchema($classes);
        $output->writeln("   <info>v1.15.0 upgrade completed successfully.</info>");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.15.0 upgrade routines...");
    }
}
