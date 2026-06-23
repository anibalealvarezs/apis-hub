<?php

namespace Classes\Upgrades;

use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpgradeManager
{
    private EntityManagerInterface $em;
    private UpgradePathFinder $pathFinder;
    /** @var UpgradeRoutineInterface[] */
    private array $routines = [];

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->loadRoutines();
        $this->pathFinder = new UpgradePathFinder($this->routines);
    }

    private function loadRoutines(): void
    {
        $routinesDir = __DIR__ . '/Routines';
        if (!is_dir($routinesDir)) {
            return;
        }

        foreach (glob($routinesDir . '/*.php') as $file) {
            require_once $file;
            $className = 'Classes\\Upgrades\\Routines\\' . basename($file, '.php');
            if (class_exists($className) && is_subclass_of($className, UpgradeRoutineInterface::class)) {
                $this->routines[] = new $className();
            }
        }
    }

    public function getPathFinder(): UpgradePathFinder
    {
        return $this->pathFinder;
    }

    /**
     * Executes the upgrade sequence, handling rollbacks on failure.
     *
     * @param UpgradeRoutineInterface[] $path
     * @param OutputInterface $output
     * @return bool True if successful, False if failed (and rolled back)
     */
    public function executeUpgrade(array $path, OutputInterface $output): bool
    {
        $executedRoutines = [];
        $needsResync = false;

        try {
            foreach ($path as $routine) {
                $froms = implode(', ', $routine->getFromVersions());
                $output->writeln("<info>Executing upgrade: [{$froms}] -> {$routine->getToVersion()}</info>");
                $output->writeln("<comment> - {$routine->getDescription()}</comment>");
                
                $routine->up($this->em, $output);
                $executedRoutines[] = $routine;

                if ($routine->requiresNuclearResync()) {
                    $needsResync = true;
                }
            }
            
            $output->writeln("\n<info>All upgrades completed successfully!</info>");

            if ($needsResync) {
                $output->writeln("\n<comment>A nuclear resync is required by one or more of the executed upgrades.</comment>");
                $output->writeln("<info>Initiating Nuclear Resync...</info>");
                
                $resyncCommand = new \Commands\NuclearResyncCommand($this->em);
                $resyncInput = new \Symfony\Component\Console\Input\ArrayInput(['--channel' => 'all']);
                $resyncInput->setInteractive(false);
                $resyncCommand->run($resyncInput, $output);
            }

            return true;
            
        } catch (\Exception $e) {
            $failedStep = end($path);
            if (!empty($executedRoutines)) {
                $failedStep = $executedRoutines[count($executedRoutines)-1];
            } else {
                $failedStep = $path[0] ?? null;
            }
            
            $stepDesc = $failedStep ? "[" . implode(', ', $failedStep->getFromVersions()) . "] -> {$failedStep->getToVersion()}" : "Unknown";
            
            $output->writeln("\n<error>Migration failed at step {$stepDesc}</error>");
            $output->writeln("<error>{$e->getMessage()}</error>");
            $output->writeln("\n<comment>Initiating rollback protocol...</comment>");
            
            // Reverse the executed routines for LIFO rollback
            $executedRoutines = array_reverse($executedRoutines);
            
            foreach ($executedRoutines as $rollbackRoutine) {
                $froms = implode(', ', $rollbackRoutine->getFromVersions());
                $output->writeln("<comment>Rolling back: {$rollbackRoutine->getToVersion()} -> [{$froms}]</comment>");
                try {
                    $rollbackRoutine->down($this->em, $output);
                } catch (\Exception $ex) {
                    $output->writeln("<error>CRITICAL: Rollback failed at step {$rollbackRoutine->getToVersion()} -> [{$froms}]</error>");
                    $output->writeln("<error>{$ex->getMessage()}</error>");
                    $output->writeln("<error>Halting rollback to prevent further corruption.</error>");
                    return false;
                }
            }
            
            $output->writeln("<info>Rollback complete. The database has been safely restored to its initial state.</info>");
            return false;
        }
    }
}
