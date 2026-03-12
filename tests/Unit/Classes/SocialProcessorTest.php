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
                'url' => 'test-page',
                'title' => 'Test Page',
                'hostname' => 'facebook.com',
                'platformId' => '123',
                'accountId' => 1,
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
                'platformId' => 'post123',
                'pageId' => 10,
                'accountId' => 1,
                'channeledAccountId' => 2,
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
