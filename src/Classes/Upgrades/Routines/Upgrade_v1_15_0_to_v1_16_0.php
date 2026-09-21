<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_15_0_to_v1_16_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.15.0'];
    }

    public function getToVersion(): string
    {
        return '1.16.0';
    }

    public function getDescription(): string
    {
        return 'Adds TypeSafe AI Query Classification schema (query_classifications, account_query_classifications) and analytical grouping optimizations.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Synchronizing Doctrine schema for v1.16.0 (TypeSafe AI Query Classification)...");
        $tool = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $tool->updateSchema($classes);
        $output->writeln("   <info>v1.16.0 upgrade completed successfully.</info>");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.16.0 upgrade routines...");
    }
}
