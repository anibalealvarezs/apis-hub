<?php

namespace Classes\Upgrades;

use Interfaces\UpgradeRoutineInterface;

class UpgradePathFinder
{
    /** @var UpgradeRoutineInterface[] */
    private array $routines;

    /**
     * @param UpgradeRoutineInterface[] $routines
     */
    public function __construct(array $routines)
    {
        $this->routines = $routines;
    }

    /**
     * Finds the shortest sequential path from the current version to the target version using BFS.
     *
     * @param string $current
     * @param string $target
     * @return UpgradeRoutineInterface[]|null Returns an array of routines or null if no path exists.
     */
    public function findPath(string $current, string $target): ?array
    {
        if ($current === $target) {
            return []; // Already at target
        }

        $queue = new \SplQueue();
        $queue->enqueue(['version' => $current, 'path' => []]);
        $visited = [$current => true];

        while (!$queue->isEmpty()) {
            $node = $queue->dequeue();
            $currVer = $node['version'];
            $path = $node['path'];

            if ($currVer === $target) {
                return $path;
            }

            foreach ($this->routines as $routine) {
                if (in_array($currVer, $routine->getFromVersions(), true)) {
                    $nextVer = $routine->getToVersion();
                    if (!isset($visited[$nextVer])) {
                        $visited[$nextVer] = true;
                        $newPath = $path;
                        $newPath[] = $routine;
                        $queue->enqueue(['version' => $nextVer, 'path' => $newPath]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Calculates the highest target version reachable from the given current version.
     * Uses version_compare to find the semantic maximum.
     *
     * @param string $current
     * @return string
     */
    public function getMaxUpgradable(string $current): string
    {
        $max = $current;
        $queue = new \SplQueue();
        $queue->enqueue($current);
        $visited = [$current => true];

        while (!$queue->isEmpty()) {
            $currVer = $queue->dequeue();
            
            if (version_compare($currVer, $max, '>')) {
                $max = $currVer;
            }

            foreach ($this->routines as $routine) {
                if (in_array($currVer, $routine->getFromVersions(), true)) {
                    $nextVer = $routine->getToVersion();
                    if (!isset($visited[$nextVer])) {
                        $visited[$nextVer] = true;
                        $queue->enqueue($nextVer);
                    }
                }
            }
        }

        return $max;
    }

    /**
     * Calculates the minimum base version required to eventually reach the target version.
     * Performs a reverse BFS starting from the target.
     *
     * @param string $target
     * @return string|null
     */
    public function getMinRequiredForTarget(string $target): ?string
    {
        $min = null;
        $queue = new \SplQueue();
        $queue->enqueue($target);
        $visited = [$target => true];

        while (!$queue->isEmpty()) {
            $currVer = $queue->dequeue();
            
            if ($min === null || version_compare($currVer, $min, '<')) {
                $min = $currVer;
            }

            foreach ($this->routines as $routine) {
                if ($routine->getToVersion() === $currVer) {
                    foreach ($routine->getFromVersions() as $prevVer) {
                        if (!isset($visited[$prevVer])) {
                            $visited[$prevVer] = true;
                            $queue->enqueue($prevVer);
                        }
                    }
                }
            }
        }

        return $min;
    }
}
