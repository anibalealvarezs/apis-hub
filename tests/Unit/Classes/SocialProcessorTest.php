<?php

    declare(strict_types=1);

    namespace Tests\Unit\Classes;

    use Classes\SocialProcessor;
    use Doctrine\Common\Collections\ArrayCollection;
    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Exception;
    use Doctrine\ORM\EntityManager;
    use stdClass;
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

        /**
         * @throws Exception
         */
        public function testProcessPages(): void
        {
            $mockPage = $this->getMockBuilder(stdClass::class)
                ->addMethods(['getContext', 'getUrl', 'getCanonicalId', 'getTitle', 'getHostname', 'getData', 'getPlatformId']) // Added getPlatformId
                ->getMock();

            $mockPage->method('getContext')->willReturn(['account' => ['accountId' => $this->faker->randomNumber()]]);
            $mockPage->method('getUrl')->willReturn($this->faker->slug());
            $mockPage->method('getCanonicalId')->willReturn((string)$this->faker->randomNumber(8));
            $mockPage->method('getTitle')->willReturn($this->faker->sentence(2));
            $mockPage->method('getHostname')->willReturn($this->faker->domainName());
            $mockPage->method('getData')->willReturn([]);
            $mockPage->method('getPlatformId')->willReturn((string)$this->faker->randomNumber(8)); // Added return value

            $pages = new ArrayCollection([$mockPage]);

            $this->conn->expects($this->once())
                ->method('executeStatement')
                ->willReturn(1);

            SocialProcessor::processPages($pages, $this->manager);

            $this->assertTrue(true);
        }

        /**
         * @throws Exception
         */
        public function testProcessPosts(): void
        {
            $mockPost = $this->getMockBuilder(stdClass::class)
                ->addMethods(['getContext', 'getUrl', 'getCanonicalId', 'getMetadata', 'getTitle', 'getType', 'getData', 'getPlatformId']) // Added getPlatformId
                ->getMock();

            $mockPost->method('getContext')->willReturn([
                'account'          => ['accountId' => $this->faker->randomNumber()],
                'channeledAccount' => ['accountId' => $this->faker->randomNumber()],
                'page'             => ['pageId' => $this->faker->randomNumber()],
            ]);
            $mockPost->method('getUrl')->willReturn($this->faker->slug());
            $mockPost->method('getCanonicalId')->willReturn((string)$this->faker->randomNumber(8));
            $mockPost->method('getMetadata')->willReturn([]);
            $mockPost->method('getTitle')->willReturn($this->faker->sentence(2));
            $mockPost->method('getType')->willReturn('post');
            $mockPost->method('getData')->willReturn([]);
            $mockPost->method('getPlatformId')->willReturn((string)$this->faker->randomNumber(8)); // Added return value

            $posts = new ArrayCollection([$mockPost]);

            $this->conn->expects($this->once())
                ->method('executeStatement')
                ->willReturn(1);

            SocialProcessor::processPosts($posts, $this->manager);

            $this->assertTrue(true);
        }
    }