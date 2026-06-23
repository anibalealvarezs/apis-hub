<?php

namespace Interfaces;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface UpgradeRoutineInterface
{
    /**
     * The valid starting versions for this routine.
     *
     * @return string[]
     */
    public function getFromVersions(): array;

    /**
     * The destination version for this routine.
     *
     * @return string
     */
    public function getToVersion(): string;

    /**
     * Executes the upgrade routine.
     *
     * @param EntityManagerInterface $em
     * @param OutputInterface $output
     * @return void
     * @throws \Exception
     */
    public function up(EntityManagerInterface $em, OutputInterface $output): void;

    /**
     * Reverts the upgrade routine in case of a rollback.
     *
     * @param EntityManagerInterface $em
     * @param OutputInterface $output
     * @return void
     * @throws \Exception
     */
    public function down(EntityManagerInterface $em, OutputInterface $output): void;

    /**
     * A brief description of what this routine does.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Specifies whether this upgrade routine fundamentally changes data structures
     * and requires a complete historical data wipe to prevent corruption.
     *
     * @return bool
     */
    public function requiresNuclearResync(): bool;
}
