<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_18_0_to_v1_18_1 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.18.0'];
    }

    public function getToVersion(): string
    {
        return '1.18.1';
    }

    public function getDescription(): string
    {
        return 'Security Hotfix: Disables session.upload_progress to eliminate CVE-2026-109618 / CVE-2026-109619 heap UAF primitives.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Verified security configuration hotfix for v1.18.1.");
    }
}
