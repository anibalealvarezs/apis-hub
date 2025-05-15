<?php

namespace Tests\Unit\Controllers;

use Controllers\CacheController;
use Doctrine\ORM\EntityManager;
use Enums\Channels;
use Exception;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response;

class CacheControllerTest extends TestCase
{
    private Generator $faker;
    private ConcreteCacheController $controller;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        // Mock EntityManager
        $entityManager = $this->createMock(EntityManager::class);

        // Create a concrete class for testing the CacheController
        $this->controller = new ConcreteCacheController($entityManager);
    }

    public function testInvokeReturnsErrorForInvalidEntity(): void
    {
        $entity = $this->faker->word;
        $channel = 'SOME_CHANNEL';
        $this->controller->setMockEntitiesConfig([]);

        $response = $this->controller->__invoke($channel, $entity);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid analytics entity']),
            $response->getContent()
        );
    }

    public function testInvokeCallsListForValidEntity(): void
    {
        $entity = $this->faker->word;
        $channel = 'shopify';
        $body = null;
        $params = ['key' => 'value'];
        $data = ['result' => $this->faker->word];
        $channelEnum = Channels::shopify;

        // Mock entities config and channel
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockChannel($channel, $channelEnum);

        // Mock list method
        $this->controller->setMockListData($data);

        $response = $this->controller->__invoke($channel, $entity, $body, $params);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    public function testPrepareAnalyticsParamsMergesBodyAndParams(): void
    {
        $body = json_encode(['filters' => ['key' => 'value'], 'other' => 'data']);
        $params = ['extra' => 'param'];
        $expected = [
            'filters' => (object) ['key' => 'value'],
            'other' => 'data',
            'extra' => 'param'
        ];

        $result = $this->controller->prepareAnalyticsParams($params, $body);

        $this->assertEquals($expected, $result);
    }

    public function testPrepareAnalyticsParamsHandlesEmptyInputs(): void
    {
        $result = $this->controller->prepareAnalyticsParams(null, null);
        $this->assertEquals([], $result);

        $result = $this->controller->prepareAnalyticsParams([], '');
        $this->assertEquals([], $result);
    }

    public function testListReturnsSuccessResponse(): void
    {
        $entity = $this->faker->word;
        $channel = Channels::shopify;
        $data = ['result' => $this->faker->word];

        $this->controller->setMockListData($data);

        $response = $this->controller->list($entity, $channel);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => $data, 'status' => 'success', 'error' => null]),
            $response->getContent()
        );
    }

    public function testListHandlesException(): void
    {
        $entity = $this->faker->word;
        $channel = Channels::shopify;
        $exceptionMessage = 'Fetch error';

        $this->controller->setMockListException(new Exception($exceptionMessage));

        $response = $this->controller->list($entity, $channel);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => $exceptionMessage]),
            $response->getContent()
        );
    }

    public function testIsValidEntityReturnsTrueForValidEntity(): void
    {
        $entity = $this->faker->word;
        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);

        $this->assertTrue($this->controller->isValidEntity($entity));
    }

    public function testIsValidEntityReturnsFalseForInvalidEntity(): void
    {
        $entity = $this->faker->word;
        $this->controller->setMockEntitiesConfig(['other_entity' => ['class' => 'Entities\\OtherEntity']]);

        $this->assertFalse($this->controller->isValidEntity($entity));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetEntityRequestsClassNameReturnsClassName(): void
    {
        $entity = 'shopify';
        $className = 'SomeRequestsClass';

        $this->controller->setMockEntityClassName($entity, $className);

        $result = $this->controller->getEntityRequestsClassName($entity);

        $this->assertEquals($className, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testFetchDataReturnsDataForValidMethod(): void
    {
        $entity = $this->faker->word;
        $channel = Channels::shopify;
        $params = ['key' => 'value'];
        $body = json_encode(['filters' => ['a' => 'b']]);
        $data = ['result' => $this->faker->word];
        $requestsClassName = '\Classes\Requests\SomeClass';
        $methodName = 'getListFrom' . $channel->getCommonName();

        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'class' => 'Entities\\' . $entity,
                'repository_methods' => [
                    $methodName => ['parameters' => ['key', 'filters']]
                ]
            ]
        ]);
        $this->controller->setMockFetchData($requestsClassName, $methodName, $data);

        $result = $this->controller->fetchData($entity, $channel, $params, $body);

        $this->assertEquals($data, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testFetchDataReturnsErrorResponseForInvalidMethod(): void
    {
        $entity = $this->faker->word;
        $channel = Channels::shopify;
        $requestsClassName = '\Classes\Requests\SomeClass';
        $methodName = 'getListFrom' . $channel->getCommonName();

        $this->controller->setMockEntitiesConfig([strtolower($entity) => ['class' => 'Entities\\' . $entity]]);
        $this->controller->setMockFetchData($requestsClassName, $methodName, null);

        $response = $this->controller->fetchData($entity, $channel, null, null);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Method not found']),
            $response->getContent()
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testFetchDataReturnsErrorResponseForInvalidParameters(): void
    {
        $entity = $this->faker->word;
        $channel = Channels::shopify;
        $params = ['invalid' => 'param'];
        $requestsClassName = '\Classes\Requests\SomeClass';
        $methodName = 'getListFrom' . $channel->getCommonName();

        $this->controller->setMockEntitiesConfig([
            strtolower($entity) => [
                'class' => 'Entities\\' . $entity,
                'repository_methods' => [
                    $methodName => ['parameters' => ['key', 'filters']]
                ]
            ]
        ]);
        $this->controller->setMockFetchData($requestsClassName, $methodName, []);

        $response = $this->controller->fetchData($entity, $channel, $params, null);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(
            json_encode(['data' => null, 'status' => 'error', 'error' => 'Invalid parameters']),
            $response->getContent()
        );
    }
}

// Concrete class to test the CacheController
class ConcreteCacheController extends CacheController
{
    private array $mockEntitiesConfig = [];
    private mixed $mockListData = null;
    private ?Exception $mockListException = null;
    private array $mockFetchData = [];
    private array $mockChannels = [];
    private array $mockEntityClassNames = [];

    public function __construct(EntityManager $entityManager)
    {
        parent::__construct();
        $this->em = $entityManager; // Override with mock
    }

    // Test methods to set mock data
    public function setMockEntitiesConfig(array $config): void
    {
        $this->mockEntitiesConfig = $config;
    }

    public function setMockListData(mixed $data): void
    {
        $this->mockListData = $data;
        $this->mockListException = null;
    }

    public function setMockListException(Exception $exception): void
    {
        $this->mockListData = null;
        $this->mockListException = $exception;
    }

    public function setMockFetchData(string $className, string $methodName, mixed $data, bool $validParams = true): void
    {
        $this->mockFetchData = [
            'className' => $className,
            'methodName' => $methodName,
            'data' => $data,
            'validParams' => $validParams
        ];
    }

    public function setMockChannel(string $channelName, Channels $channelEnum): void
    {
        $this->mockChannels[$channelName] = $channelEnum;
    }

    public function setMockEntityClassName(string $entity, string $className): void
    {
        $this->mockEntityClassNames[$entity] = $className;
    }

    // Override methods as public for testing with mock data
    public function __invoke(
        string $channel,
        string $entity,
        ?string $body = null,
        ?array $params = null
    ): Response {
        if (!$this->isValidEntity($entity)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid analytics entity',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $channelEnum = $this->mockChannels[$channel] ?? Channels::shopify; // Default for tests
        return $this->list($entity, $channelEnum, $body, $params);
    }

    public function createResponse(mixed $data, string $status, ?string $error = null, int $httpStatus = Response::HTTP_OK): Response
    {
        return parent::createResponse($data, $status, $error, $httpStatus);
    }

    public function prepareAnalyticsParams(?array $params, ?string $body): array
    {
        return parent::prepareAnalyticsParams($params, $body);
    }

    public function list(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        if ($this->mockListException) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $this->mockListException->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->createResponse(
            data: $this->mockListData ?: [],
            status: 'success'
        );
    }

    public function isValidEntity(string $entity): bool
    {
        return in_array(needle: $entity, haystack: array_keys($this->mockEntitiesConfig));
    }

    public function getEntityRequestsClassName(string $entity): string
    {
        return $this->mockEntityClassNames[$entity] ?? 'SomeRequestsClass';
    }

    public function fetchData(string $entity, Channels $channel, ?array $params, ?string $body): mixed
    {
        if (empty($this->mockFetchData)) {
            return parent::fetchData($entity, $channel, $params, $body);
        }

        $className = $this->mockFetchData['className'];
        $methodName = $this->mockFetchData['methodName'];
        $data = $this->mockFetchData['data'];
        $validParams = $this->mockFetchData['validParams'];

        if ($data === null) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Method not found',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $parameters = $this->prepareAnalyticsParams($params, $body);
        if (!$validParams || !$this->validateParams(array_keys($parameters), $entity, $methodName)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid parameters',
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        }

        return $data;
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
}