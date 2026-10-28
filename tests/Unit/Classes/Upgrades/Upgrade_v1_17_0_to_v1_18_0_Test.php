<?php

namespace Tests\Unit\Classes\Upgrades;

use Classes\Upgrades\Routines\Upgrade_v1_17_0_to_v1_18_0;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class Upgrade_v1_17_0_to_v1_18_0_Test extends TestCase
{
    public function testMetadataAndVersions(): void
    {
        $routine = new Upgrade_v1_17_0_to_v1_18_0();

        $this->assertEquals(['1.17.0', '1.17.1', '1.17.2'], $routine->getFromVersions());
        $this->assertEquals('1.18.0', $routine->getToVersion());
        $this->assertFalse($routine->requiresNuclearResync());
        $this->assertNotEmpty($routine->getDescription());
    }

    public function testUpExecution(): void
    {
        $routine = new Upgrade_v1_17_0_to_v1_18_0();

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('executeStatement');

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);

        $config = $this->createMock(Configuration::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);
        $em->method('getConfiguration')->willReturn($config);

        $output = new BufferedOutput();
        $routine->up($em, $output);

        $outText = $output->fetch();
        $this->assertStringContainsString('Registering Google Ads provider and channel', $outText);
        $this->assertStringContainsString('Creating channeled indexes', $outText);
        $this->assertStringContainsString('v1.18.0 upgrade completed successfully', $outText);
    }

    public function testDownExecution(): void
    {
        $routine = new Upgrade_v1_17_0_to_v1_18_0();

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $output = new BufferedOutput();
        $routine->down($em, $output);

        $outText = $output->fetch();
        $this->assertStringContainsString('Reverting v1.18.0 upgrade routines', $outText);
    }
}
