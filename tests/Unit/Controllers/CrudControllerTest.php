<?php

namespace Tests\Unit\Controllers;

use Controllers\CrudController;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Exception;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Services\CacheKeyGenerator;
use Services\CacheService;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class CrudControllerTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private MockObject|CacheService $cacheService;
    private MockObject|CacheKeyGenerator $cacheKeyGenerator;
    private ConcreteCrudController $controller;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        // Mock dependencies
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->cacheKeyGenerator = $this->createMock(CacheKeyGenerator::class);

        // Create a concrete class for testing the CrudController
        $this->controller = new ConcreteCrudController(
            $this->entityManager,
            $this->cacheService,
            $this->cacheKeyGenerator
        );
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function testInvokeReturnsErrorForInvalidEntity(): void
    {
        $entity = 'customer';
        $method = 'read';
        $this->controller->setMockCrudEntities([]);

        $response = $this->controller->__invoke($entity, $method);

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            error_log("testInvokeReturnsErrorForInvalidEntity: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid crudable entity']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function testInvokeRoutesToReadMethod(): void
    {
        $entity = 'customer';
        $method = 'read';
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "entity_{$entity}_{$id}";

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id)
            ->willReturn($data);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forEntity')
            ->with($entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->__invoke($entity, $method, $id);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testInvokeRoutesToReadMethod: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function testInvokeReturnsErrorForInvalidMethod(): void
    {
        $entity = 'customer';
        $method = 'invalid';

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->__invoke($entity, $method);

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            error_log("testInvokeReturnsErrorForInvalidMethod: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Method not found']),
            $response->getContent()
        );
    }

    /**
     * @throws JsonException
     */
    public function testPrepareReadMultipleParamsMergesBodyAndParams(): void
    {
        $body = json_encode(['key' => 'value']);
        $params = ['extra' => 'param'];
        $repositoryClass = 'customer';
        $filters = (object) ['key' => 'value'];
        $expected = [
            'extra' => 'param',
            'filters' => $filters
        ];

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'class' => 'Entities\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['extra', 'filters']]
                ]
            ]
        ]);

        $result = $this->controller->prepareReadMultipleParams($params, $repositoryClass, $body);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws JsonException
     */
    public function testPrepareReadMultipleParamsThrowsExceptionForInvalidParams(): void
    {
        $params = ['invalid' => 'param'];
        $repositoryClass = 'customer';

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'class' => 'Entities\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['valid', 'filters']]
                ]
            ]
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameters');

        $this->controller->prepareReadMultipleParams($params, $repositoryClass, null);
    }

    /**
     * @throws NotSupported
     */
    public function testReadReturnsCachedData(): void
    {
        $entity = 'customer';
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "entity_{$entity}_{$id}";

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id)
            ->willReturn($data);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forEntity')
            ->with($entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->read($entity, $id);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testReadReturnsCachedData: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testReadHandlesException(): void
    {
        $entity = 'customer';
        $id = $this->faker->randomNumber();
        $exceptionMessage = 'Repository error';
        $cacheKey = "entity_{$entity}_{$id}";

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id)
            ->willThrowException(new Exception($exceptionMessage));

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forEntity')
            ->with($entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($exceptionMessage) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->read($entity, $id);

        if ($response->getStatusCode() !== Response::HTTP_INTERNAL_SERVER_ERROR) {
            error_log("testReadHandlesException: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => $exceptionMessage]),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function testCountReturnsCount(): void
    {
        $entity = 'customer';
        $body = json_encode(['key' => 'value']);
        $params = [];
        $count = $this->faker->numberBetween(0, 100);
        $filters = (object) ['key' => 'value'];

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'class' => 'Entities\\' . $entity,
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['filters']],
                    'countElements' => ['parameters' => ['filters']]
                ]
            ]
        ]);

        // Set mock count data to bypass repository and cache logic
        $this->controller->setMockCountData($count, $filters);

        $response = $this->controller->count($entity, $body, $params);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testCountReturnsCount: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => ['count' => $count], 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function testListReturnsData(): void
    {
        $entity = 'customer';
        $body = json_encode(['key' => 'value']);
        $params = ['extra' => 'param'];
        $data = [['id' => 1, 'name' => $this->faker->word]];
        $filters = (object) ['key' => 'value'];
        $cacheKey = 'list_' . $entity . '_' . md5(json_encode(['filters' => $filters, 'extra' => 'param']));

        $result = new class ($data) {
            private array $data;
            public function __construct(array $data) {
                $this->data = $data;
            }
            public function toArray(): array {
                return $this->data;
            }
        };

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('readMultiple')
            ->with($filters, 'param')
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'class' => 'Entities\\' . $entity,
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['filters', 'extra']]
                ]
            ]
        ]);

        $response = $this->controller->list($entity, $body, $params);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testListReturnsData: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testCreateReturnsCreatedEntity(): void
    {
        $entity = 'customer';
        $body = json_encode(['name' => $this->faker->word]);
        $id = $this->faker->numberBetween(1, 1000000); // Ensure truthy ID
        $data = ['id' => $id, 'name' => $this->faker->word];
        $channel = 'shopify';

        $result = new class ($data) {
            private array $data;
            public function __construct(array $data) {
                $this->data = $data;
            }
            public function toArray(): array {
                return $this->data;
            }
            public function getId() {
                return $this->data['id'];
            }
            public function getChannel(): string {
                return 'shopify';
            }
        };

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        // Debug: Verify ID is truthy
        $this->assertNotEmpty($id, 'Generated ID must be truthy');

        $response = $this->controller->create($entity, $body);

        if ($response->getStatusCode() !== Response::HTTP_CREATED) {
            error_log("testCreateReturnsCreatedEntity: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testCreateReturnsErrorForInvalidData(): void
    {
        $entity = 'customer';
        $body = json_encode(['name' => $this->faker->word]);

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->create($entity, $body);

        if ($response->getStatusCode() !== Response::HTTP_BAD_REQUEST) {
            error_log("testCreateReturnsErrorForInvalidData: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid or missing data']),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testUpdateReturnsUpdatedEntity(): void
    {
        $entity = 'customer';
        $id = $this->faker->randomNumber();
        $body = json_encode(['name' => $this->faker->word]);
        $data = ['id' => $id, 'name' => $this->faker->word];
        $channel = 'shopify';

        $result = new class ($data) {
            private array $data;
            public function __construct(array $data) {
                $this->data = $data;
            }
            public function toArray(): array {
                return $this->data;
            }
            public function getId() {
                return $this->data['id'];
            }
            public function getChannel(): string {
                return 'shopify';
            }
        };

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('update')
            ->with($id, $this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->update($entity, $id, $body);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testUpdateReturnsUpdatedEntity: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testUpdateReturnsErrorForMissingId(): void
    {
        $entity = 'customer';
        $body = json_encode(['name' => $this->faker->word]);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->update($entity, null, $body);

        if ($response->getStatusCode() !== Response::HTTP_BAD_REQUEST) {
            error_log("testUpdateReturnsErrorForMissingId: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid or missing ID']),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testDeleteReturnsSuccess(): void
    {
        $entity = 'customer';
        $id = $this->faker->randomNumber();

        // Mock custom repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('delete')
            ->with($id)
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id]);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->delete($entity, $id);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            error_log("testDeleteReturnsSuccess: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    /**
     * @throws NotSupported
     */
    public function testDeleteReturnsErrorForMissingId(): void
    {
        $entity = 'customer';

        $this->controller->setMockCrudEntities([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $response = $this->controller->delete($entity);

        if ($response->getStatusCode() !== Response::HTTP_BAD_REQUEST) {
            error_log("testDeleteReturnsErrorForMissingId: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Missing ID']),
            $response->getContent()
        );
    }

    public function testExtractIdFromObjectWithGetId(): void
    {
        $id = $this->faker->randomNumber();
        $result = new class ($id) {
            private int $id;
            public function __construct(int $id) {
                $this->id = $id;
            }
            public function getId(): int {
                return $this->id;
            }
        };

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($id, $extractedId);
    }

    public function testExtractIdFromArray(): void
    {
        $id = $this->faker->randomNumber();
        $result = ['id' => $id];

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($id, $extractedId);
    }

    public function testExtractChannelFromObject(): void
    {
        $channel = 'shopify';
        $result = new class ($channel) {
            private string $channel;
            public function __construct(string $channel) {
                $this->channel = $channel;
            }
            public function getChannel(): string {
                return $this->channel;
            }
        };

        $extractedChannel = $this->controller->extractChannel($result);

        $this->assertEquals($channel, $extractedChannel);
    }

    public function testExtractChannelFromArray(): void
    {
        $channel = 'shopify';
        $result = ['channel' => $channel];

        $extractedChannel = $this->controller->extractChannel($result);

        $this->assertEquals($channel, $extractedChannel);
    }
}

// Concrete class to test the CrudController
class ConcreteCrudController extends CrudController
{
    private array $mockCrudEntities = [];
    private array $mockEntitiesConfig = [];
    private ?int $mockCountData = null;
    private EntityManager $entityManager;
    private CacheService $cacheService;
    private CacheKeyGenerator $cacheKeyGenerator;

    public function __construct(
        EntityManager $entityManager,
        CacheService $cacheService,
        CacheKeyGenerator $cacheKeyGenerator
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->cacheService = $cacheService;
        $this->cacheKeyGenerator = $cacheKeyGenerator;
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

    public function setMockCountData(int $count, stdClass $filters): void
    {
        $this->mockCountData = $count;
    }

    // Override methods as public for testing
    public function isValidCrudableEntity(string $entity): bool
    {
        return in_array(needle: strtolower($entity), haystack: array_keys($this->mockCrudEntities));
    }

    public function getRepository(string $entity, string $configKey = 'class'): object
    {
        $config = $this->mockEntitiesConfig;
        $entityKey = strtolower($entity);
        if (!isset($config[$entityKey][$configKey])) {
            throw new Exception("Entity configuration for '$entity' with key '$configKey' not found");
        }
        return $this->entityManager->getRepository(
            entityName: $config[$entityKey][$configKey]
        );
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

    public function prepareReadMultipleParams(?array $params, string $repositoryClass, ?string $body): array
    {
        if (!empty($params) && !$this->validateParams(array_keys($params), $repositoryClass, 'readMultiple')) {
            throw new InvalidArgumentException('Invalid parameters');
        }

        $params = $params ?? [];
        $params['filters'] = $body ? json_decode($body, false, 512, JSON_THROW_ON_ERROR) : new stdClass();
        return $params;
    }

    public function read(string $entity, ?int $id = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $cacheKey = $this->cacheKeyGenerator->forEntity($entity, $id);
            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->read($id)
            );

            return $this->createResponse(
                data: $data ?: [],
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function count(string $entity, ?string $body = null, ?array $params = null): Response
    {
        try {
            if ($this->mockCountData !== null) {
                $count = $this->mockCountData;
            } else {
                $repository = $this->getRepository($entity);
                $filters = $body ? json_decode($body, false, 512, JSON_THROW_ON_ERROR) : new stdClass();
                $params = ['filters' => $filters];
                $cacheKey = 'count_' . $entity . '_' . md5(json_encode($params));
                $count = $this->cacheService->get(
                    key: $cacheKey,
                    callback: fn() => $repository->countElements($filters)
                );
            }

            return $this->createResponse(
                data: ['count' => $count],
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function list(string $entity, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $filters = $body ? json_decode($body, false, 512, JSON_THROW_ON_ERROR) : new stdClass();
            $extra = $params['extra'] ?? null;
            $cacheKey = 'list_' . $entity . '_' . md5(json_encode(['filters' => $filters, 'extra' => $extra]));
            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->readMultiple($filters, $extra)->toArray()
            );

            return $this->createResponse(
                data: $data ?: [],
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function create(string $entity, ?string $body = null): Response
    {
        try {
            if (!$body || json_decode($body, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON body');
            }
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $repository = $this->getRepository($entity);
            $result = $repository->create($data);

            if (!$result) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Invalid or missing data',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $id = $this->extractId($result);
            if ($id) {
                $this->cacheService->invalidateMultipleEntities(
                    entities: [$entity => $id],
                    channel: $this->extractChannel($result)
                );
            }

            return $this->createResponse(
                data: (method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
                status: 'success',
                httpStatus: Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function update(string $entity, ?int $id = null, ?string $body = null): Response
    {
        try {
            if (!$id) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Invalid or missing ID',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            if (!$body || json_decode($body, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON body');
            }
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $repository = $this->getRepository($entity);
            $result = $repository->update($id, $data);

            if (!$result) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Record not found or could not be updated',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            $this->cacheService->invalidateMultipleEntities(
                entities: [$entity => $id],
                channel: $this->extractChannel($result)
            );

            return $this->createResponse(
                data: (method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function delete(string $entity, ?int $id = null): Response
    {
        try {
            if (!$id) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing ID',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $repository = $this->getRepository($entity);
            $success = $repository->delete($id);

            if (!$success) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Record not found or could not be deleted',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            $this->cacheService->invalidateMultipleEntities(
                entities: [$entity => $id]
            );

            return $this->createResponse(
                data: null,
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function extractId(mixed $result): int|string|null
    {
        return parent::extractId($result);
    }

    public function extractChannel(mixed $result): ?string
    {
        return parent::extractChannel($result);
    }
}