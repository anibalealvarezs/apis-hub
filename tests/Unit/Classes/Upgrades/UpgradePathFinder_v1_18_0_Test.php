<?php

namespace Tests\Unit\Classes\Upgrades;

use Classes\Upgrades\Routines\Upgrade_v1_17_0_to_v1_18_0;
use Classes\Upgrades\UpgradePathFinder;
use PHPUnit\Framework\TestCase;

class UpgradePathFinder_v1_18_0_Test extends TestCase
{
    public function testFindsPathFrom1_17VersionsTo1_18_0(): void
    {
        $routine = new Upgrade_v1_17_0_to_v1_18_0();
        $finder = new UpgradePathFinder([$routine]);

        foreach (['1.17.0', '1.17.1', '1.17.2'] as $fromVersion) {
            $path = $finder->findPath($fromVersion, '1.18.0');
            $this->assertNotNull($path, "Failed finding upgrade path from {$fromVersion} to 1.18.0");
            $this->assertCount(1, $path);
            $this->assertSame($routine, $path[0]);
        }
    }
}
