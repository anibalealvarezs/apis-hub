<?php

namespace Tests\Unit\Controllers;

use Controllers\ChanneledCrudController;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Analytics\Channel as ChannelEntity;
use Entities\Entity;
use Exception;
use Exceptions\ConfigurationException;
use Helpers\Helpers;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionException;
use ReflectionProperty;
use Services\CacheKeyGenerator;
use Services\CacheService;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\BaseUnitTestCase;

class ChanneledCrudControllerTest extends BaseUnitTestCase
{
    private MockObject|CacheService $cacheService;
    private MockObject|CacheKeyGenerator $cacheKeyGenerator;
    private ConcreteChanneledCrudController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
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
     * Builds a repository mock compatible with EntityManager::getRepository() return typing.
     *
     * @param array<int, string> $methods
     */
    private function createRepositoryMock(array $methods): MockObject|EntityRepository
    {
        $mockBuilder = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor();

        $existingMethods = [];
        $nonExistingMethods = [];

        $reflectionClass = new \ReflectionClass(EntityRepository::class);

        foreach ($methods as $method) {
            if ($reflectionClass->hasMethod($method)) {
                $existingMethods[] = $method;
            } else {
                $nonExistingMethods[] = $method;
            }
        }

        if (!empty($existingMethods)) {
            $mockBuilder->onlyMethods($existingMethods);
        }
        if (!empty($nonExistingMethods)) {
            $mockBuilder->addMethods($nonExistingMethods);
        }

        return $mockBuilder->getMock();
    }

    /**
     * @throws ReflectionException
     * @throws ConfigurationException
     */
    public function testInvokeReturnsErrorForInvalidEntity(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $this->controller->setMockCrudEntities([]);
        $this->controller->setMockChannelsConfig([
            'shopify' => ['enabled' => true],
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid crudable entity']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws ConfigurationException
     */
    public function testInvokeReturnsErrorForInvalidChannel(): void
    {
        $entity = 'customer';
        $channel = 'INVALID';
        $method = 'read';
        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([]);

        $response = $this->controller->__invoke($entity, $channel, $method);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid channel']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     * @throws ConfigurationException
     */
    public function testInvokeReturnsErrorForDisabledChannel(): void
    {
        $entity = 'customer';
        $channel = 'shopify';
        $method = 'read';
        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            'shopify' => ['enabled' => false],
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            'shopify' => ['enabled' => false],
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

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
        $channelName = 'shopify';
        $method = 'read';
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "channeled_entity_{$channelName}_{$entity}_$id";

        $this->assertNotNull($id, 'Faker generated a null ID');

        $customerRepository = $this->createRepositoryMock(['__call']);
        $customerRepository->expects($this->once())
            ->method('__call')
            ->with('read', [$id, false, (object)['channel' => $channelName]])
            ->willReturn($data);

        $channelRepository = $this->createRepositoryMock(['findOneBy']);
        $channelRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => $channelName])
            ->willReturn($this->getChannelEntity($channelName));

        $this->entityManager->expects($this->any())
            ->method('getRepository')
            ->willReturnMap([
                ['Entities\\Channeled\\customer', $customerRepository],
                [ChannelEntity::class, $channelRepository],
            ]);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with($channelName, $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channelName => ['enabled' => true],
        ]);

        $this->controller->setMockChannelsConfigOverride([
            $channelName => ['enabled' => true],
        ]);

        $response = $this->controller->__invoke($entity, $channelName, $method, $id);

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

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            'shopify' => ['enabled' => true],
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            'shopify' => ['enabled' => true],
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method);

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
        $channel = $this->getChannelEntity('shopify');
        $filters = (object)['key' => 'value', 'channel' => $channel->getName()];
        $expected = [
            'extra'      => 'param',
            'filters'    => $filters,
            'limit'      => 100,
            'pagination' => 0,
        ];

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'channeled_class'    => 'Entities\\Channeled\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['extra', 'filters']],
                ],
            ],
        ]);

        $result = $this->controller->prepareChanneledReadMultipleParams($repositoryClass, $params, $repositoryClass, $body, $channel);

        $this->assertEquals($expected, $result);
    }

    public function testPrepareChanneledReadMultipleParamsThrowsExceptionForInvalidParams(): void
    {
        $params = ['invalid' => 'param'];
        $repositoryClass = 'customer';
        $channel = $this->getChannelEntity('shopify');

        $this->controller->setMockEntitiesConfig([
            'customer' => [
                'channeled_class'    => 'Entities\\Channeled\\customer',
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['valid', 'filters']],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameters');

        $this->controller->prepareChanneledReadMultipleParams($repositoryClass, $params, $repositoryClass, null, $channel);
    }

    /**
     * @throws ReflectionException
     * @throws \Doctrine\DBAL\Exception
     */
    public function testReadReturnsCachedData(): void
    {
        $entity = 'customer';
        $channel = $this->getChannelEntity('shopify');
        $id = $this->faker->randomNumber();
        $data = ['id' => $id, 'name' => $this->faker->word];
        $cacheKey = "channeled_entity_{$channel->getName()}_{$entity}_$id";

        $this->assertNotNull($id, 'Faker generated a null ID');

        $repository = $this->createRepositoryMock(['__call']);
        $repository->expects($this->once())
            ->method('__call')
            ->with('read', [$id, false, (object)['channel' => $channel->getName()]])
            ->willReturn($data);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with($channel->getName(), $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->read($entity, $channel, $id);

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
        $channel = $this->getChannelEntity('shopify');
        $id = $this->faker->randomNumber();
        $exceptionMessage = 'Repository error';
        $cacheKey = "channeled_entity_{$channel->getName()}_{$entity}_$id";

        $this->assertNotNull($id, 'Faker generated a null ID');

        $repository = $this->createRepositoryMock(['__call']);
        $repository->expects($this->once())
            ->method('__call')
            ->with('read', [$id, false, (object)['channel' => $channel->getName()]])
            ->willThrowException(new Exception($exceptionMessage));

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheKeyGenerator->expects($this->once())
            ->method('forChanneledEntity')
            ->with($channel->getName(), $entity, $id)
            ->willReturn($cacheKey);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), $this->anything())
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->read($entity, $channel, $id);

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
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['key' => 'value']);
        $params = [];
        $count = $this->faker->numberBetween(0, 100);
        $filters = (object)['key' => 'value', 'channel' => $channel->getName()];
        $cacheKey = 'channeled_count_'.$entity.'_'.$channel->getName().'_'.md5(serialize($filters));

        $repository = $this->createRepositoryMock(['countElements']);
        $repository->expects($this->once())
            ->method('countElements')
            ->with($filters)
            ->willReturn($count);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), 300)
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'channeled_class'    => 'Entities\\Channeled\\'.$entity,
                'repository_methods' => [
                    'readMultiple'  => ['parameters' => ['filters']],
                    'countElements' => ['parameters' => ['filters']],
                ],
            ],
        ]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->count($entity, $channel, $body, $params);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => ['count' => $count], 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    public function testAggregateReturnsData(): void
    {
        $entity = 'product_metric';
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['filters' => ['status' => 'active']]);
        $params = [
            'aggregations' => ['total' => 'SUM(value)'],
            'groupBy'      => ['metricDate'],
        ];
        $expectedData = [['total' => 500, 'metricDate' => '2024-01-01']];

        $repository = $this->createRepositoryMock(['aggregate']);
        $repository->expects($this->once())
            ->method('aggregate')
            ->with(
                ['total' => 'SUM(value)'],
                ['metricDate'],
                $this->callback(fn($filters) => $filters->status === 'active' && $filters->channel === $channel->getName())
            )
            ->willReturn($expectedData);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'channeled_class'    => 'Entities\\Channeled\\'.$entity,
                'repository_methods' => [
                    'aggregate' => ['parameters' => ['filters', 'aggregations', 'groupBy']],
                ],
            ],
        ]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->aggregate($entity, $channel, $body, $params);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => $expectedData, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    public function testRealAggregateIncludesRepositoryMetaInResponse(): void
    {
        $entity = 'product_metric';
        $channel = $this->makeChannel();
        $params = [
            'aggregations' => ['total' => 'SUM(value)'],
            'groupBy'      => ['metricDate'],
            'filters'      => ['status' => 'active'],
        ];
        $expectedData = [['total' => 500, 'metricDate' => '2024-01-01']];
        $expectedMeta = [
            'execution_path'  => 'legacy',
            'fallback_reason' => 'no_optimized_strategy_matched',
        ];

        $repository = new class ($expectedData, $expectedMeta) {
            public ?object $lastFilters = null;

            public function __construct(
                private readonly array $data,
                private readonly array $meta
            )
            {
            }

            public function aggregate(
                array   $aggregations,
                array   $groupBy,
                ?object $filters = null,
                ?string $startDate = null,
                ?string $endDate = null,
                ?string $orderBy = null,
                string  $orderDir = 'ASC'
            ): array
            {
                $this->lastFilters = $filters;

                return $this->data;
            }

            public function getLastAggregateMeta(): array
            {
                return $this->meta;
            }
        };

        $realController = new AggregateMetaExposingChanneledCrudController($repository);

        $response = $realController->aggregatePublic($entity, $channel, null, $params);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('success', $payload['status']);
        $this->assertSame($expectedData, $payload['data']);
        $this->assertSame('active', $repository->lastFilters?->status);
        $this->assertSame($channel->getName(), $repository->lastFilters?->channel);
        $this->assertSame('legacy', $payload['meta']['execution_path']);
        $this->assertSame('no_optimized_strategy_matched', $payload['meta']['fallback_reason']);
        $this->assertFalse($payload['meta']['cached']);
        $this->assertFalse($payload['meta']['cacheable']);
    }

    public function testRealAggregatePreservesDynamicMetaKeysInResponse(): void
    {
        $entity = 'product_metric';
        $channel = $this->makeChannel();
        $params = [
            'aggregations' => ['total' => 'SUM(value)'],
            'groupBy'      => ['metricDate'],
            'filters'      => ['status' => 'active'],
        ];
        $expectedData = [['total' => 700, 'metricDate' => '2024-02-01']];
        $dynamicMeta = [
            'execution_path'          => 'optimized',
            'fallback_reason'         => null,
            'driver_contract_version' => 'v2026.05.04',
            'meta_probe'              => [
                'custom_flag' => true,
                'weights'     => [0.25, 0.75],
            ],
        ];

        $repository = new class ($expectedData, $dynamicMeta) {
            public function __construct(
                private readonly array $data,
                private readonly array $meta
            )
            {
            }

            public function aggregate(
                array   $aggregations,
                array   $groupBy,
                ?object $filters = null,
                ?string $startDate = null,
                ?string $endDate = null,
                ?string $orderBy = null,
                string  $orderDir = 'ASC'
            ): array
            {
                return $this->data;
            }

            public function getLastAggregateMeta(): array
            {
                return $this->meta;
            }
        };

        $realController = new AggregateMetaExposingChanneledCrudController($repository);

        $response = $realController->aggregatePublic($entity, $channel, null, $params);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame($expectedData, $payload['data']);
        $this->assertSame('optimized', $payload['meta']['execution_path']);
        $this->assertNull($payload['meta']['fallback_reason']);
        $this->assertSame('v2026.05.04', $payload['meta']['driver_contract_version']);
        $this->assertTrue($payload['meta']['meta_probe']['custom_flag']);
        $this->assertSame([0.25, 0.75], $payload['meta']['meta_probe']['weights']);
        $this->assertFalse($payload['meta']['cached']);
        $this->assertFalse($payload['meta']['cacheable']);
    }

    /**
     * @throws ReflectionException
     */
    public function testListReturnsData(): void
    {
        $entity = 'customer';
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['key' => 'value']);
        $params = ['extra' => 'param'];
        $data = [['id' => 1, 'name' => $this->faker->word]];
        $filters = (object)['key' => 'value', 'channel' => $channel->getName()];
        $cacheKey = 'channeled_list_'.$entity.'_'.$channel->getName().'_'.md5(serialize($filters));

        $result = new class ($data) {
            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function toArray(): array
            {
                return $this->data;
            }
        };

        $repository = $this->createRepositoryMock(['readMultiple']);
        $repository->expects($this->once())
            ->method('readMultiple')
            ->with($filters, 'param')
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey, $this->anything(), 600)
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'channeled_class'    => 'Entities\\Channeled\\'.$entity,
                'repository_methods' => [
                    'readMultiple' => ['parameters' => ['filters', 'extra']],
                ],
            ],
        ]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->list($entity, $channel, $body, $params);

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
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['name' => $this->faker->word]);
        $data = ['id' => $this->faker->randomNumber(), 'name' => $this->faker->word, 'channel' => $channel->getName()];
        $id = $data['id'];

        $result = new class ($data) {
            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function toArray(): array
            {
                return $this->data;
            }

            public function getId()
            {
                return $this->data['id'];
            }
        };

        $repository = $this->createRepositoryMock(['create']);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->getName());

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->create($entity, $channel, $body);

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
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['name' => $this->faker->word]);

        $repository = $this->createRepositoryMock(['create']);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(stdClass::class))
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->create($entity, $channel, $body);

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
        $channel = $this->getChannelEntity('shopify');
        $id = $this->faker->numberBetween(1, 1000);
        $body = json_encode(['name' => $this->faker->word]);
        $data = ['id' => $id, 'name' => $this->faker->word, 'channel' => $channel->getName()];

        $result = new class ($data) {
            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function toArray(): array
            {
                return $this->data;
            }

            public function getId()
            {
                return $this->data['id'];
            }
        };

        $repository = $this->createRepositoryMock(['update']);
        $repository->expects($this->once())
            ->method('update')
            ->with($id, $this->isInstanceOf(stdClass::class))
            ->willReturn($result);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->getName());

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->update($entity, $channel, $id, $body);

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
        $channel = $this->getChannelEntity('shopify');
        $body = json_encode(['name' => $this->faker->word]);

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->update($entity, $channel, null, $body);

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
        $channel = $this->getChannelEntity('shopify');
        $id = $this->faker->numberBetween(1, 1000);

        $repository = $this->createRepositoryMock(['delete']);
        $repository->expects($this->once())
            ->method('delete')
            ->with($id)
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with('Entities\\Channeled\\'.$entity)
            ->willReturn($repository);

        $this->cacheService->expects($this->once())
            ->method('invalidateMultipleEntities')
            ->with([$entity => $id], $channel->getName());

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->delete($entity, $channel, $id);

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
        $channel = $this->getChannelEntity('shopify');

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            $channel->getName() => ['enabled' => true],
        ]);

        $response = $this->controller->delete($entity, $channel);

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

            public function __construct(int $id)
            {
                $this->id = $id;
            }

            public function getId(): int
            {
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

            public function __construct(int $id)
            {
                $this->id = $id;
            }

            public function getPlatformId(): int
            {
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

            public function __construct(string $code)
            {
                parent::__construct();
                $this->code = $code;
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getId(): ?int
            {
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

        $this->controller->setMockCrudEntities([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['channeled_class' => 'Entities\\Channeled\\'.$entity]]);
        $this->controller->setMockChannelsConfig([
            'shopify' => ['enabled' => true],
        ]);

        // Set channel config override
        $this->controller->setMockChannelsConfigOverride([
            'shopify' => ['enabled' => true],
        ]);

        $response = $this->controller->__invoke($entity, $channel, $method, $id);

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

    private function makeChannel(): ChannelEntity
    {
        $channel = new ChannelEntity();
        $channel->setName('shopify');

        $reflection = new ReflectionProperty(Entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($channel, 1);

        return $channel;
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
        EntityManager     $entityManager,
        CacheService      $cacheService,
        CacheKeyGenerator $cacheKeyGenerator
    )
    {
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

    public function setMockChannelsConfig(array $config): void
    {
        $this->mockChannelsConfig = $config;
    }

    public function setMockChannelsConfigOverride(array $config): void
    {
        if ($config === $this->mockChannelsConfigOverride) {
            return;
        }

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

        return $this->entityManager->getRepository($config[$entityKey][$configKey]);
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

    public function prepareCrudParams(?array $params, ?string $body): array
    {
        return parent::prepareCrudParams($params, $body);
    }

    public function prepareChanneledReadMultipleParams(
        string        $entity,
        ?array        $params,
        string        $repositoryClass,
        ?string       $body,
        ChannelEntity $channel
    ): array
    {
        if (!empty($params) && !$this->validateParams(array_keys($params), $repositoryClass, 'readMultiple')) {
            throw new InvalidArgumentException('Invalid parameters');
        }
        $params = $this->prepareCrudParams($params, $body);
        if (!isset($params['filters']->channel)) {
            $params['filters']->channel = $channel->getName();
        }

        return $params;
    }

    public function read(string $entity, ChannelEntity $channel, int|string|null $id = null, bool $rawData = false, array $hideFields = []): Response
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
            $filters = (object)['channel' => $channel->getName()];
            $cacheKey = $this->cacheKeyGenerator->forChanneledEntity($channel->getName(), $entity, $id);

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->__call('read', [$id, false, $filters])
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

    public function count(string $entity, ChannelEntity $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $params = $this->prepareChanneledReadMultipleParams(
                entity: $entity,
                params: $params,
                repositoryClass: $entity,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_count_'.$entity.'_'.$channel->getName().'_'.md5(serialize($params['filters']));

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

    public function list(string $entity, ChannelEntity $channel, ?string $body = null, ?array $params = null, bool $rawData = false, array $hideFields = []): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $params = $this->prepareChanneledReadMultipleParams(
                entity: $entity,
                params: $params,
                repositoryClass: $entity,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_list_'.$entity.'_'.$channel->getName().'_'.md5(serialize($params['filters']));

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

    public function create(string $entity, ChannelEntity $channel, ?string $body = null): Response
    {
        try {
            $data = Helpers::bodyToObject($body);
            if (!isset($data->channel)) {
                $data->channel = $channel->getName();
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
                    channel: $channel->getName()
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

    public function update(string $entity, ChannelEntity $channel, int|string|null $id = null, ?string $body = null): Response
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
                $data->channel = $channel->getName();
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
                channel: $channel->getName()
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

    public function aggregate(string $entity, ChannelEntity $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository($entity);
            $params = $this->prepareCrudParams($params, $body);
            if (!isset($params['filters']->channel)) {
                $params['filters']->channel = $channel->getName();
            }

            $aggregations = (array)($params['aggregations'] ?? []);
            $groupBy = (array)($params['groupBy'] ?? []);

            if (empty($aggregations)) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing aggregations parameter',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $data = $repository->aggregate(
                $aggregations,
                $groupBy,
                $params['filters'] ?? null,
                $params['startDate'] ?? null,
                $params['endDate'] ?? null
            );

            return $this->createResponse(
                data: $data,
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

    public function delete(string $entity, ChannelEntity $channel, int|string|null $id = null): Response
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
                channel: $channel->getName()
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
        string          $entity,
        string          $channel,
        string          $method,
        int|string|null $id = null,
        ?string         $body = null,
        ?array          $params = null
    ): Response
    {

        $channelsConfig = $this->getChannelsConfig();
        $channelEntity = $this->getChannelEntity($channel);
        if (!$channelEntity || !isset($channelsConfig[$channelEntity->getName()])) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid channel',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if ($channelsConfig[$channelEntity->getName()]['enabled'] === false) {
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
                'read' => $this->read($entity, $channelEntity, $id),
                'update' => $this->update($entity, $channelEntity, $id, $body),
                'delete' => $this->delete($entity, $channelEntity, $id),
                'create' => $this->create($entity, $channelEntity, $body),
                'aggregate', 'count', 'list' => $this->$method($entity, $channelEntity, $body, $params),
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

    protected function getChannelEntity(string $name): ?ChannelEntity
    {
        $channelRepository = $this->entityManager->getRepository(ChannelEntity::class);

        return $channelRepository->findOneBy(['name' => $name]);
    }
}

class AggregateMetaExposingChanneledCrudController extends ChanneledCrudController
{
    public function __construct(private readonly object $repository)
    {
        parent::__construct();
    }

    /**
     * @throws ReflectionException
     */
    public function aggregatePublic(string $entity, ChannelEntity $channel, ?string $body = null, ?array $params = null): Response
    {
        return $this->aggregate($entity, $channel, $body, $params);
    }

    public function getRepository(string $entity, string $configKey = 'channeled_class'): object
    {
        return $this->repository;
    }

    public function prepareChanneledReadMultipleParams(
        string        $entity,
        ?array        $params,
        string        $repositoryClass,
        ?string       $body,
        ChannelEntity $channel
    ): array
    {
        $prepared = parent::prepareCrudParams($params, $body);
        $prepared['filters'] = $prepared['filters'] ?? new stdClass();
        if (!isset($prepared['filters']->channel)) {
            $prepared['filters']->channel = $channel->getName();
        }

        return $prepared;
    }
}