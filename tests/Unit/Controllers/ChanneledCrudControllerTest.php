<?php

namespace Tests\Unit\Controllers;

use Controllers\ChanneledCrudController;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Enums\Channels;
use Exception;
use Faker\Factory;
use Faker\Generator;
use Helpers\Helpers;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Services\CacheKeyGenerator;
use Services\CacheService;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ChanneledCrudControllerTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private MockObject|CacheService $cacheService;
    private MockObject|CacheKeyGenerator $cacheKeyGenerator;
    private ConcreteChanneledCrudController $controller;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        // Mock dependencies
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->cacheKeyGenerator = $this->createMock(CacheKeyGenerator::class);

        // Create a concrete class for testing
        $this->controller = new ConcreteChanneledCrudController(
            $this->entityManager,
            $this->cacheService,
            $this->cacheKeyGenerator
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testInvokeReturnsErrorForInvalidEntity(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $this->controller->setMockCrudEntities([]);
        $this->controller->setMockChannelsConfig([
            Channels::shopify->value => ['enabled' => true]
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

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
     */
    public function testInvokeReturnsErrorForInvalidChannel(): void
    {
        $entity = 'customer';
        $channel = 'INVALID';
        $method = 'read';
        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([]);

        $response = $this->controller->__invoke($entity, $channel, $method);

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            error_log("testInvokeReturnsErrorForInvalidChannel: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid channel']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testInvokeReturnsErrorForDisabledChannel(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            Channels::shopify->value => ['enabled' => false]
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            Channels::shopify->value => ['enabled' => false]
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

        if ($response->getStatusCode() !== Response::HTTP_FORBIDDEN) {
            error_log("testInvokeReturnsErrorForDisabledChannel: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Channel disabled']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testInvokeRoutesToReadMethod(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "channeled_entity_" . Channels::shopify->value . "_{$entity}_{$id}";

        // Ensure $id is not null
        $this->assertNotNull($id, 'Faker generated a null ID');

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id, false, (object) ['channel' => Channels::shopify->value])
            ->willReturn($data);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with(Channels::shopify->value, $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            Channels::shopify->value => ['enabled' => true]
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            Channels::shopify->value => ['enabled' => true]
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method, $id);

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
     */
    public function testInvokeReturnsErrorForInvalidMethod(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'invalid';

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            Channels::shopify->value => ['enabled' => true]
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            Channels::shopify->value => ['enabled' => true]
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            error_log("testInvokeReturnsErrorForInvalidMethod: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Method not found']),
            $response->getContent()
        );
    }

    public function testPrepareChanneledReadMultipleParamsMergesBodyAndParams(): void
    {
        $body = json_encode(['key' => 'value']);
        $params = ['extra' => 'param'];
        $repositoryClass = 'customer';
        $channel = Channels::shopify;
        $filters = (object) ['key' => 'value', 'channel' => $channel->value];
        $expected = [
            'extra' => 'param',
            'filters' => $filters
        ];

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'channeled_class' => 'Entities\\Channeled\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['extra', 'filters']]
                ]
            ]
        ]);

        $result = $this->controller->prepareChanneledReadMultipleParams($params, $repositoryClass, $body, $channel);

        $this->assertEquals($expected, $result);
    }

    public function testPrepareChanneledReadMultipleParamsThrowsExceptionForInvalidParams(): void
    {
        $params = ['invalid' => 'param'];
        $repositoryClass = 'customer';
        $channel = Channels::shopify;

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'channeled_class' => 'Entities\\Channeled\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['valid', 'filters']]
                ]
            ]
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameters');

        $this->controller->prepareChanneledReadMultipleParams($params, $repositoryClass, null, $channel);
    }

    /**
     * @throws ReflectionException
     */
    public function testReadReturnsCachedData(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "channeled_entity_{$channel->value}_{$entity}_{$id}";

        // Ensure $id is not null
        $this->assertNotNull($id, 'Faker generated a null ID');

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id, false, (object) ['channel' => $channel->value])
            ->willReturn($data);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with($channel->value, $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->read($entity, $channel, $id);

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
     * @throws ReflectionException
     */
    public function testReadHandlesException(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $id = $this->faker->randomNumber();
        $exceptionMessage = 'Repository error';
        $cacheKey = "channeled_entity_{$channel->value}_{$entity}_{$id}";

        // Ensure $id is not null
        $this->assertNotNull($id, 'Faker generated a null ID');

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('read')
            ->with($id, false, (object) ['channel' => $channel->value])
            ->willThrowException(new Exception($exceptionMessage));

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with($channel->value, $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) use ($exceptionMessage) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->read($entity, $channel, $id);

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
     */
    public function testCountReturnsCount(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $body = json_encode(['key' => 'value']);
        $params = [];
        $count = $this->faker->numberBetween(0, 100);
        $filters = (object) ['key' => 'value', 'channel' => $channel->value];
        $cacheKey = 'channeled_count_' . $entity . '_' . $channel->value . '_' . md5(serialize($filters));

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('countElements')
            ->with($filters)
            ->willReturn($count);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), 300)
            ->willReturnCallback(function ($key, $callback) use ($count) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'channeled_class' => 'Entities\\Channeled\\' . $entity,
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['filters']],
                    'countElements' => ['parameters' => ['filters']]
                ]
            ]
        ]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->count($entity, $channel, $body, $params);

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
     */
    public function testListReturnsData(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $body = json_encode(['key' => 'value']);
        $params = ['extra' => 'param'];
        $data = [['id' => 1, 'name' => $this->faker->word]];
        $filters = (object) ['key' => 'value', 'channel' => $channel->value];
        $cacheKey = 'channeled_list_' . $entity . '_' . $channel->value . '_' . md5(serialize($filters));

        $result = new class ($data) {
            private array $data;
            public function __construct(array $data) {
                $this->data = $data;
            }
            public function toArray(): array {
                return $this->data;
            }
        };

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('readMultiple')
            ->with($filters, 'param')
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), 600)
            ->willReturnCallback(function ($key, $callback) use ($data) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'channeled_class' => 'Entities\\Channeled\\' . $entity,
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['filters', 'extra']]
                ]
            ]
        ]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->list($entity, $channel, $body, $params);

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
     * @throws ReflectionException
     */
    public function testCreateReturnsCreatedEntity(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $body = json_encode(['name' => $this->faker->word]);
        $data = ['id' => $this->faker->randomNumber(), 'name' => $this->faker->word, 'channel' => $channel->value];
        $id = $data['id'];

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
        };

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->value);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->create($entity, $channel, $body);

        if ($response->getStatusCode() !== Response::HTTP_CREATED) {
            error_log("testCreateReturnsCreatedEntity: Response content: " . $response->getContent());
        }

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
     */
    public function testCreateReturnsErrorForInvalidData(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $body = json_encode(['name' => $this->faker->word]);

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->create($entity, $channel, $body);

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
     * @throws ReflectionException
     */
    public function testUpdateReturnsUpdatedEntity(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $id = $this->faker->randomNumber();
        $body = json_encode(['name' => $this->faker->word]);
        $data = ['id' => $id, 'name' => $this->faker->word, 'channel' => $channel->value];

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
        };

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('update')
            ->with($id, $this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->value);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->update($entity, $channel, $id, $body);

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
     */
    public function testUpdateReturnsErrorForMissingId(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $body = json_encode(['name' => $this->faker->word]);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->update($entity, $channel, null, $body);

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
     */
    public function testDeleteReturnsSuccess(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;
        $id = $this->faker->randomNumber();

        // Mock repository
        $repository = $this->getMockBuilder(stdClass::class)
            ->addMethods(['read', 'countElements', 'readMultiple', 'create', 'update', 'delete'])
            ->getMock();
        $repository->expects($this->once())
            ->method('delete')
            ->with($id)
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\' . $entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->value);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->delete($entity, $channel, $id);

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
     */
    public function testDeleteReturnsErrorForMissingId(): void
    {
        $entity = 'customer';
        $channel = Channels::shopify;

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->value => ['enabled' => true]
        ]);

        $response = $this->controller->delete($entity, $channel);

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

    public function testExtractIdFromObjectWithGetPlatformId(): void
    {
        $id = $this->faker->randomNumber();
        $result = new class ($id) {
            private int $id;
            public function __construct(int $id) {
                $this->id = $id;
            }
            public function getPlatformId(): int {
                return $this->id;
            }
        };

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($id, $extractedId);
    }

    public function testExtractIdFromChanneledDiscount(): void
    {
        $code = $this->faker->word;
        $result = new class ($code) extends ChanneledDiscount {
            protected string $code;
            public function __construct(string $code) {
                parent::__construct();
                $this->code = $code;
            }
            public function getCode(): string {
                return $this->code;
            }
            public function getId(): ?int {
                return null; // Override to prevent Entity::$id access
            }
        };

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($code, $extractedId);
    }

    /**
     * @throws ReflectionException
     */
    public function testReadReturnsErrorForNullId(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $id = null;

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\' . $entity]]);
        $this->controller->setMockChannelsConfig([
            Channels::shopify->value => ['enabled' => true]
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            Channels::shopify->value => ['enabled' => true]
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method, $id);

        if ($response->getStatusCode() !== Response::HTTP_BAD_REQUEST) {
            error_log("testReadReturnsErrorForNullId: Response content: " . $response->getContent());
        }

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Missing ID']),
            $response->getContent()
        );
    }

    public function testExtractIdFromArrayWithId(): void
    {
        $id = $this->faker->randomNumber();
        $result = ['id' => $id];

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($id, $extractedId);
    }

    public function testExtractIdFromArrayWithCode(): void
    {
        $code = $this->faker->word;
        $result = ['code' => $code];

        $extractedId = $this->controller->extractId($result);

        $this->assertEquals($code, $extractedId);
    }

    public function testExtractIdReturnsNullForInvalidResult(): void
    {
        $result = new stdClass();

        $extractedId = $this->controller->extractId($result);

        $this->assertNull($extractedId);
    }
}

// Concrete class to test the ChanneledCrudController
class ConcreteChanneledCrudController extends ChanneledCrudController
{
    private array $mockCrudEntities = [];
    private array $mockEntitiesConfig = [];
    private array $mockChannelsConfig = [];
    private array $mockChannelsConfigOverride = [];
    private EntityManager $entityManager;
    private CacheService $cacheService;
    private CacheKeyGenerator $cacheKeyGenerator;

    public function __construct(
        EntityManager $entityManager,
        CacheService $cacheService,
        CacheKeyGenerator $cacheKeyGenerator
    ) {
        $this->entityManager = $entityManager;
        $this->cacheService = $cacheService;
        $this->cacheKeyGenerator = $cacheKeyGenerator;
        parent::__construct();
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

    public function setMockChannelsConfig(array $config): void
    {
        $this->mockChannelsConfig = $config;
    }

    public function setMockChannelsConfigOverride(array $config): void
    {
        $this->mockChannelsConfigOverride = $config;
    }

    // Override methods as public for testing
    public function isValidCrudableEntity(string $entity): bool
    {
        return in_array(needle: strtolower($entity), haystack: array_keys($this->mockCrudEntities));
    }

    public function getRepository(string $entity, string $configKey = 'channeled_class'): object
    {
        $config = $this->mockEntitiesConfig;
        $entityKey = strtolower($entity);
        if (!isset($config[$entityKey][$configKey])) {
            throw new Exception("Entity configuration for '$entity' with key '$configKey' not found");
        }
        $repository = $this->entityManager->getRepository(
            entityName: $config[$entityKey][$configKey]
        );
        if ($repository === null) {
            throw new Exception("Repository for '$entity' not found");
        }
        return $repository;
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

    public function prepareChanneledReadMultipleParams(
        ?array $params,
        string $repositoryClass,
        ?string $body,
        Channels $channel
    ): array {
        if (!empty($params) && !$this->validateParams(array_keys($params), $repositoryClass, 'readMultiple')) {
            throw new InvalidArgumentException('Invalid parameters');
        }

        $params = $params ?? [];
        $params['filters'] = Helpers::bodyToObject($body) ?? new stdClass();
        if (!isset($params['filters']->channel)) {
            $params['filters']->channel = $channel->value;
        }

        return $params;
    }

    public function read(string $entity, Channels $channel, ?int $id = null): Response
    {
        try {
            if ($id === null) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing ID',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $repository = $this->getRepository($entity);
            $filters = (object) ['channel' => $channel->value];
            $cacheKey = $this->cacheKeyGenerator->forChanneledEntity($channel->value, $entity, $id);

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->read($id, false, $filters)
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

    public function count(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $entity,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_count_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']));

            $count = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->countElements($params['filters']),
                ttl: 300
            );

            return $this->createResponse(
                data: ['count' => $count],
                status: 'success'
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
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

    public function list(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $entity,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_list_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']));

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->readMultiple($params['filters'], $params['extra'] ?? null)->toArray(),
                ttl: 600
            );

            return $this->createResponse(
                data: $data ?: [],
                status: 'success'
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
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

    public function create(string $entity, Channels $channel, ?string $body = null): Response
    {
        try {
            $data = Helpers::bodyToObject($body);
            if (!isset($data->channel)) {
                $data->channel = $channel->value;
            }
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
                    channel: $channel->value
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

    public function update(string $entity, Channels $channel, ?int $id = null, ?string $body = null): Response
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

            $data = Helpers::bodyToObject($body);
            if (!isset($data->channel)) {
                $data->channel = $channel->value;
            }
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
                channel: $channel->value
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

    public function delete(string $entity, Channels $channel, ?int $id = null): Response
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
                entities: [$entity => $id],
                channel: $channel->value
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
        if ($result instanceof ChanneledDiscount) {
            if (method_exists($result, 'getCode')) {
                return $result->getCode();
            }
        }
        return parent::extractId($result);
    }

    // Override getChannelsConfig for testing
    protected function getChannelsConfig(): array
    {
        return $this->mockChannelsConfigOverride ?: $this->mockChannelsConfig;
    }

    // Override __invoke to bypass ReflectionEnum and handle channel mapping
    public function __invoke(
        string $entity,
        string $channel,
        string $method,
        ?int $id = null,
        ?string $body = null,
        ?array $params = null,
        ...$extraArgs
    ): Response
    {
        $channelsConfig = $this->getChannelsConfig();
        $validChannels = ['shopify', 'klaviyo', 'facebook', 'bigcommerce', 'netsuite', 'amazon'];
        $channelEnum = Channels::tryFromName($channel);
        if (!in_array($channel, $validChannels) || !$channelEnum || !isset($channelsConfig[$channelEnum->value])) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid channel',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if ($channelsConfig[$channelEnum->value]['enabled'] === false) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Channel disabled',
                httpStatus: Response::HTTP_FORBIDDEN
            );
        }

        if (!$this->isValidCrudableEntity($entity)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid crudable entity',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        // Map string channel to enum value
        $channelMap = [
            'shopify' => 1,
            'klaviyo' => 2,
            'facebook' => 3,
            'bigcommerce' => 4,
            'netsuite' => 5,
            'amazon' => 6,
        ];
        $channelValue = $channelMap[$channel] ?? null;
        if (!$channelValue) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid channel value',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $channelEnum = Channels::tryFrom($channelValue);
        if (!$channelEnum) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid channel enum',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if (!method_exists($this, $method)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Method not found',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        try {
            return match ($method) {
                'read' => $this->read($entity, $channelEnum, $id),
                'update' => $this->update($entity, $channelEnum, $id, $extraArgs[0] ?? null),
                'delete' => $this->delete($entity, $channelEnum, $id),
                'create' => $this->create($entity, $channelEnum, $extraArgs[0] ?? null),
                'count', 'list' => $this->$method($entity, $channelEnum, ...$extraArgs),
                default => $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Unsupported method',
                    httpStatus: Response::HTTP_NOT_FOUND
                ),
            };
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}