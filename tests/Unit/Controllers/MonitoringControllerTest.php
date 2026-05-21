<?php

    namespace Tests\Unit\Controllers;

    use Controllers\MonitoringController;
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\Query;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Job;
    use Enums\JobStatus;
    use PHPUnit\Framework\MockObject\MockObject;
    use ReflectionClass;
    use Repositories\JobRepository;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Tests\Unit\BaseUnitTestCase;

    class MonitoringControllerTest extends BaseUnitTestCase
    {
        private MonitoringController $controller;
        protected EntityManagerInterface|MockObject $entityManager;
        private $jobRepository;

        protected function setUp(): void
        {
            parent::setUp();
            $this->entityManager = $this->createMock(EntityManager::class);
            $this->jobRepository = $this->createMock(JobRepository::class);
            $connection = $this->createMock(Connection::class);

            $this->entityManager->method('getRepository')
                ->with(Job::class)
                ->willReturn($this->jobRepository);

            $this->entityManager->method('getConnection')
                ->willReturn($connection);

            $connection->method('fetchAllAssociative')->willReturn([]);

            $this->controller = new MonitoringController();

            $reflection = new ReflectionClass($this->controller);
            $emProperty = $reflection->getProperty('em');
            $emProperty->setAccessible(true);
            $emProperty->setValue($this->controller, $this->entityManager);
        }

        public function testIndexReturnsHtmlResponse(): void
        {
            $response = $this->controller->index();
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
            $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        }

        public function testDataReturnsJsonInstances(): void
        {
            $this->jobRepository->method('findBy')->willReturn([]);

            $queryBuilder = $this->createMock(QueryBuilder::class);
            $query = $this->createMock(Query::class);
            $this->entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
            $queryBuilder->method('select')->willReturnSelf();
            $queryBuilder->method('from')->willReturnSelf();
            $queryBuilder->method('groupBy')->willReturnSelf();
            $queryBuilder->method('getQuery')->willReturn($query);
            $query->method('getSingleScalarResult')->willReturn(0);
            $query->method('getArrayResult')->willReturn([]);

            $response = $this->controller->data();
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
            $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));

            $content = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('containers', $content);
        }

        public function testJobActionInvalidAction(): void
        {
            $jobId = $this->faker->randomNumber();
            $mockJob = $this->createMock(Job::class);
            $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

            $request = new Request([], ['id' => $jobId, 'action' => 'invalid']);
            $response = $this->controller->jobAction($request);

            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        }

        public function testJobActionCancelSuccess(): void
        {
            $jobId = $this->faker->randomNumber();
            $mockJob = $this->createMock(Job::class);
            $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

            $request = new Request([], ['id' => $jobId, 'action' => 'cancel']);

            $this->jobRepository->expects($this->once())
                ->method('update')
                ->with($jobId, $this->callback(function ($data) {
                    return $data->status === JobStatus::cancelled->value;
                }))
                ->willReturn(['id' => $jobId]);

            $response = $this->controller->jobAction($request);
            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        }

        public function testJobActionRetrySuccess(): void
        {
            $jobId = $this->faker->randomNumber();
            $newJobId = $this->faker->randomNumber();
            $channel = $this->faker->word();
            $entity = $this->faker->word();
            $payload = [$this->faker->word() => $this->faker->word()];

            $mockJob = $this->createMock(Job::class);
            $mockJob->method('getChannel')->willReturn($channel);
            $mockJob->method('getEntity')->willReturn($entity);
            $mockJob->method('getPayload')->willReturn($payload);

            $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

            $this->jobRepository->expects($this->once())
                ->method('create')
                ->with($this->callback(function ($data) use ($channel, $entity) {
                    return $data->channel === $channel
                        && $data->entity === $entity
                        && $data->status === JobStatus::scheduled->value;
                }))
                ->willReturn(['id' => $newJobId]);

            $request = new Request([], ['id' => $jobId, 'action' => 'retry']);
            $response = $this->controller->jobAction($request);

            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
            $content = json_decode($response->getContent(), true);
            $this->assertStringContainsString("Original #$jobId history preserved", $content['message']);
        }

        public function testJobActionPriorityAdjustSuccess(): void
        {
            $jobId = $this->faker->randomNumber();
            $mockJob = $this->createMock(Job::class);
            $mockJob->method('getPriority')->willReturn(2);

            $mockJob->expects($this->once())
                ->method('setPriority')
                ->with(3);

            $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

            $this->entityManager->expects($this->once())->method('persist')->with($mockJob);
            $this->entityManager->expects($this->once())->method('flush');

            $payload = json_encode(['id' => $jobId, 'action' => 'priority_adjust', 'delta' => 1]);
            $request = Request::create('/api/monitoring/jobs/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

            $response = $this->controller->jobAction($request);
            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

            $content = json_decode($response->getContent(), true);
            $this->assertTrue($content['success']);
            $this->assertSame(3, $content['priority']);
        }

        public function testJobActionPrioritySetRejectsNonNumericValue(): void
        {
            $jobId = $this->faker->randomNumber();
            $mockJob = $this->createMock(Job::class);
            $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

            $this->entityManager->expects($this->never())->method('persist');
            $this->entityManager->expects($this->never())->method('flush');

            $payload = json_encode(['id' => $jobId, 'action' => 'priority_set', 'priority' => 'high']);
            $request = Request::create('/api/monitoring/jobs/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

            $response = $this->controller->jobAction($request);
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

            $content = json_decode($response->getContent(), true);
            $this->assertStringContainsString('numeric', $content['error']);
        }
    }
