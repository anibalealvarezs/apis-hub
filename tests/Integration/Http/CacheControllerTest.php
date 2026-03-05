<?php

namespace Tests\Integration\Http;

use Controllers\CacheController;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\BaseIntegrationTestCase;

class CacheControllerTest extends BaseIntegrationTestCase
{
    private CacheController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Inject the test EntityManager into Helpers so the controller uses it
        if (class_exists(\Helpers\Helpers::class)) {
            $reflection = new \ReflectionClass(\Helpers\Helpers::class);
            $property = $reflection->getProperty('entityManager');
            $property->setAccessible(true);
            $property->setValue(null, $this->entityManager);
        }

        $this->controller = new CacheController();
    }

    public function testInvalidChannelReturnsError(): void
    {
        $response = ($this->controller)(
            channel: 'invalid-channel',
            entity: 'customer'
        );

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertStringContainsString('Invalid channel', $response->getContent());
    }

    public function testInvalidEntityReturnsError(): void
    {
        $response = ($this->controller)(
            channel: 'shopify',
            entity: 'invalid-entity'
        );

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertStringContainsString('Invalid analytics entity', $response->getContent());
    }

    public function testValidRequestSchedulesJob(): void
    {
        // 1. Act: Provide a valid channel and entity
        $response = ($this->controller)(
            channel: 'facebook',
            entity: 'customer',
            body: json_encode(['some' => 'body']),
            params: ['some' => 'param']
        );

        // 2. Assert HTTP Response
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('Caching job successfully scheduled in background', $response->getContent());

        // 3. Assert Database state
        $jobRepo = $this->entityManager->getRepository(\Entities\Job::class);
        $jobs = $jobRepo->findAll();
        
        $this->assertCount(1, $jobs, "One job should have been created in the database.");
        /** @var \Entities\Job $job */
        $job = $jobs[0];
        
        $this->assertEquals('facebook', $job->getChannel());
        $this->assertEquals('customer', $job->getEntity());
        $this->assertEquals(JobStatus::scheduled->value, $job->getStatus());
        
        $payload = $job->getPayload();
        $this->assertEquals(json_encode(['some' => 'body']), $payload['body']);
        $this->assertEquals(['some' => 'param'], $payload['params']);

        // 4. Act: Submitting another request while one is active should throw conflict
        $responseConflict = ($this->controller)(
            channel: 'facebook',
            entity: 'customer'
        );

        $this->assertEquals(Response::HTTP_CONFLICT, $responseConflict->getStatusCode());
        $this->assertStringContainsString('already an active caching process', $responseConflict->getContent());
    }
}
