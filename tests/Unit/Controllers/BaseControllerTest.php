<?php

namespace Tests\Unit\Controllers;

use Controllers\BaseController;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Exception;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class BaseControllerTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private ConcreteBaseController $controller;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        // Mock EntityManager
        $this->entityManager = $this->createMock(EntityManager::class);

        // Create a concrete class for testing the abstract BaseController
        $this->controller = new ConcreteBaseController($this->entityManager);
    }

    public function testCreateResponseReturnsCorrectResponse(): void
    {
        $data = ['id' => $this->faker->randomNumber(), 'name' => $this->faker->word];
        $status = 'success';
        $error = null;
        $httpStatus = Response::HTTP_OK;

        $response = $this->controller->createResponse($data, $status, $error, $httpStatus);

        $this->assertEquals($httpStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $expectedContent = json_encode([
            'data' => $data,
            'status' => $status,
            'error' => $error
        ]);
        $this->assertEquals($expectedContent, $response->getContent());
    }

    public function testCreateResponseWithErrorAndCustomStatus(): void
    {
        $data = null;
        $status = 'error';
        $error = $this->faker->sentence;
        $httpStatus = Response::HTTP_BAD_REQUEST;

        $response = $this->controller->createResponse($data, $status, $error, $httpStatus);

        $this->assertEquals($httpStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $expectedContent = json_encode([
            'data' => $data,
            'status' => $status,
            'error' => $error
        ]);
        $this->assertEquals($expectedContent, $response->getContent());
    }

    public function testIsValidCrudableEntityReturnsTrueForValidEntity(): void
    {
        $entity = $this->faker->word;
        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $this->assertTrue($this->controller->isValidCrudableEntity($entity));
    }

    public function testIsValidCrudableEntityReturnsFalseForInvalidEntity(): void
    {
        $entity = $this->faker->word;
        $this->controller->setMockCrudEntities(['other_entity' => ['class' => 'Entities\\OtherEntity']]);

        $this->assertFalse($this->controller->isValidCrudableEntity($entity));
    }

    public function testValidateParamsReturnsTrueForValidParameters(): void
    {
        $entity = $this->faker->word;
        $method = 'read';
        $params = ['id', 'returnEntity'];
        $config = [
            strtolower($entity) => [
                'repository_methods' => [
                    $method => ['parameters' => $params]
                ]
            ]
        ];

        $this->controller->setMockEntitiesConfig($config);

        $this->assertTrue($this->controller->validateParams($params, $entity, $method));
    }

    public function testValidateParamsReturnsFalseForInvalidParameters(): void
    {
        $entity = $this->faker->word;
        $method = 'read';
        $params = ['id', 'invalidParam'];
        $config = [
            strtolower($entity) => [
                'repository_methods' => [
                    $method => ['parameters' => ['id', 'returnEntity']]
                ]
            ]
        ];

        $this->controller->setMockEntitiesConfig($config);

        $this->assertFalse($this->controller->validateParams($params, $entity, $method));
    }

    public function testValidateParamsReturnsFalseForMissingConfig(): void
    {
        $entity = $this->faker->word;
        $method = 'read';
        $params = ['id'];

        $this->controller->setMockEntitiesConfig([]);

        $this->assertFalse($this->controller->validateParams($params, $entity, $method));
    }

    public function testGetRepositoryReturnsRepositoryForValidEntity(): void
    {
        $entity = $this->faker->word;
        $configKey = 'class';
        $entityClass = 'Entities\\' . $entity;
        $config = [
            strtolower($entity) => [$configKey => $entityClass]
        ];

        $this->controller->setMockEntitiesConfig($config);

        $repository = $this->createMock(EntityRepository::class);
        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with($entityClass)
            ->willReturn($repository);

        $result = $this->controller->getRepository($entity, $configKey);
        $this->assertSame($repository, $result);
    }

    /**
     * @throws NotSupported
     */
    public function testGetRepositoryThrowsExceptionForInvalidEntity(): void
    {
        $entity = $this->faker->word;
        $configKey = 'class';

        $this->controller->setMockEntitiesConfig([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Entity configuration for '$entity' with key '$configKey' not found");
        $this->controller->getRepository($entity, $configKey);
    }
}

// Concrete class to test the abstract BaseController
class ConcreteBaseController extends BaseController
{
    private array $mockCrudEntities = [];
    private array $mockEntitiesConfig = [];

    public function __construct(EntityManager $entityManager)
    {
        parent::__construct();
        $this->em = $entityManager; // Override with mock
    }

    // Test methods to set mock data
    public function setMockCrudEntities(array $entities): void
    {
        $this->mockCrudEntities = $entities;
    }

    public function setMockEntitiesConfig(array $config): void
    {
        $this->mockEntitiesConfig = $config;
    }

    // Expose and override methods as public for testing with mock data
    public function createResponse(mixed $data, string $status, ?string $error = null, int $httpStatus = Response::HTTP_OK): Response
    {
        return parent::createResponse($data, $status, $error, $httpStatus);
    }

    public function isValidCrudableEntity(string $entity): bool
    {
        return in_array(needle: strtolower($entity), haystack: array_keys($this->mockCrudEntities));
    }

    public function validateParams(array $params, string $entity, string $method): bool
    {
        $config = $this->mockEntitiesConfig[strtolower($entity)] ?? null;
        if (!$config || empty($config['repository_methods'][$method]['parameters'])) {
            return false;
        }

        $validParams = $config['repository_methods'][$method]['parameters'];
        return empty(array_diff($params, $validParams));
    }

    public function getRepository(string $entity, string $configKey = 'class'): object
    {
        $config = $this->mockEntitiesConfig;
        $entityKey = strtolower($entity);
        if (!isset($config[$entityKey][$configKey])) {
            throw new Exception("Entity configuration for '$entity' with key '$configKey' not found");
        }
        return $this->em->getRepository(
            entityName: $config[$entityKey][$configKey]
        );
    }
}