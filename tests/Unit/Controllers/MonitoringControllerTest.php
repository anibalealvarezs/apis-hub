<?php

namespace Tests\Unit\Controllers;

use Controllers\MonitoringController;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use PHPUnit\Framework\TestCase;
use Repositories\JobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Helpers\Helpers;
use Enums\JobStatus;

class MonitoringControllerTest extends TestCase
{
    private MonitoringController $controller;
    private $entityManager;
    private $jobRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->jobRepository = $this->createMock(JobRepository::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);

        $this->entityManager->method('getRepository')
            ->with(Job::class)
            ->willReturn($this->jobRepository);
            
        $this->entityManager->method('getConnection')
            ->willReturn($connection);

        // Mock statement for fetchAllAssociative
        $connection->method('fetchAllAssociative')->willReturn([]);

        $this->controller = new MonitoringController();
        
        // Inject mock EM into controller
        $reflection = new \ReflectionClass($this->controller);
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
        // Mock findBy to return empty or mock data
        $this->jobRepository->method('findBy')->willReturn([]);
        
        // Mock count queries in data()
        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
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
        $jobId = 1;
        $mockJob = $this->createMock(Job::class);
        $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

        $request = new Request([], ['id' => $jobId, 'action' => 'invalid']);
        $response = $this->controller->jobAction($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testJobActionCancelSuccess(): void
    {
        $jobId = 123;
        $mockJob = $this->createMock(Job::class);
        $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);

        $request = new Request([], ['id' => $jobId, 'action' => 'cancel']);
        
        $this->jobRepository->expects($this->once())
            ->method('update')
            ->with($jobId, $this->callback(function($data) {
                return $data->status === JobStatus::cancelled->value;
            }))
            ->willReturn(['id' => $jobId]);

        $response = $this->controller->jobAction($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testJobActionRetrySuccess(): void
    {
        $jobId = 123;
        $mockJob = $this->createMock(Job::class);
        $mockJob->method('getChannel')->willReturn('facebook');
        $mockJob->method('getEntity')->willReturn('metric');
        $mockJob->method('getPayload')->willReturn(['foo' => 'bar']);
        
        $this->jobRepository->method('find')->with($jobId)->willReturn($mockJob);
            
        $this->jobRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function($data) {
                return $data->channel === 'facebook' && 
                       $data->entity === 'metric' &&
                       $data->status === JobStatus::scheduled->value;
            }))
            ->willReturn(['id' => 456]);

        $request = new Request([], ['id' => $jobId, 'action' => 'retry']);
        $response = $this->controller->jobAction($request);
        
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Original #123 history preserved', $content['message']);
    }
}
