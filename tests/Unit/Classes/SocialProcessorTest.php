<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use Classes\SocialProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Tests\Unit\BaseUnitTestCase;

class SocialProcessorTest extends BaseUnitTestCase
{
    private $conn;
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = $this->createMock(Connection::class);
        $this->manager = $this->createMock(EntityManager::class);
        $this->manager->method('getConnection')->willReturn($this->conn);
    }

    public function testProcessPages(): void
    {
        $pages = new ArrayCollection([
            (object) [
                'url' => $this->faker->slug(),
                'title' => $this->faker->sentence(2),
                'hostname' => $this->faker->domainName(),
                'platformId' => (string) $this->faker->randomNumber(8),
                'accountId' => $this->faker->randomNumber(),
                'data' => []
            ]
        ]);

        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        SocialProcessor::processPages($pages, $this->manager);

        $this->assertTrue(true);
    }

    public function testProcessPosts(): void
    {
        $posts = new ArrayCollection([
            (object) [
                'platformId' => (string) $this->faker->randomNumber(8),
                'pageId' => $this->faker->randomNumber(),
                'accountId' => $this->faker->randomNumber(),
                'channeledAccountId' => $this->faker->randomNumber(),
                'data' => []
            ]
        ]);

        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        SocialProcessor::processPosts($posts, $this->manager);

        $this->assertTrue(true);
    }

}
