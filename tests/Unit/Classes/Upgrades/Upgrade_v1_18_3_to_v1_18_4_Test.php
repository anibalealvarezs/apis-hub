<?php

namespace Tests\Unit\Classes\Upgrades;

use Classes\Upgrades\Routines\Upgrade_v1_18_3_to_v1_18_4;
use Classes\Upgrades\UpgradeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class Upgrade_v1_18_3_to_v1_18_4_Test extends TestCase
{
    public function testManagerDiscoversNewReleaseWithoutSkippingEarlierMigration(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $finder = (new UpgradeManager($em))->getPathFinder();
        self::assertSame('1.18.4', $finder->getMaxUpgradable('1.18.3'));
        $path = $finder->findPath('1.18.2', '1.18.4');
        self::assertCount(2, $path);
        self::assertSame('1.18.3', $path[0]->getToVersion());
        self::assertSame('1.18.4', $path[1]->getToVersion());
        self::assertFalse($path[1]->requiresNuclearResync());
    }

    public function testRepeatUpgradeAndRollbackDoNotAccessStoredData(): void
    {
        $routine = $this->getMockBuilder(Upgrade_v1_18_3_to_v1_18_4::class)->onlyMethods(['runtimeCapabilities'])->getMock();
        $routine->method('runtimeCapabilities')->willReturn(['fixture capability' => true]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');
        $em->expects(self::never())->method('flush');
        $output = new BufferedOutput();
        $routine->up($em, $output);
        $routine->up($em, $output);
        $routine->down($em, $output);
        self::assertStringContainsString('No cached data rewrite or automatic resync', $output->fetch());
    }

    public function testMissingLockedDependenciesFailBeforeDatabaseWork(): void
    {
        $routine = $this->getMockBuilder(Upgrade_v1_18_3_to_v1_18_4::class)->onlyMethods(['runtimeCapabilities'])->getMock();
        $routine->method('runtimeCapabilities')->willReturn(['GA4 source compatibility checks' => false]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Install the locked release dependencies');
        $routine->up($em, new BufferedOutput());
    }
}
