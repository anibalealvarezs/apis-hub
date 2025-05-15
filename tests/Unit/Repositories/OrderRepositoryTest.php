<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\OrderRepository;

class OrderRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private OrderRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        $entityManager->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('addSelect')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('leftJoin')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = new OrderRepository($entityManager, $classMetadata);
        $reflection = new ReflectionClass($this->repository);
        $entityNameProperty = $reflection->getProperty('_entityName');
        $entityNameProperty->setValue($this->repository, $this->entityName);
        $emProperty = $reflection->getProperty('_em');
        $emProperty->setValue($this->repository, $entityManager);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderSelect(): void
    {
        $addSelectCallCount = 0;
        $leftJoinCallCount = 0;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->callback(function ($arg) use (&$addSelectCallCount,) {
                $addSelectExpected = ['o', 'c', 'p', 'd'];
                error_log("testCreateBaseQueryBuilderSelect: addSelect call #$addSelectCallCount with arg=" . json_encode($arg));
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg, "addSelect does not match for call #$addSelectCallCount");
                $addSelectCallCount++;
                return true;
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->willReturnCallback(function (...$args) use (&$leftJoinCallCount) {
                error_log("testCreateBaseQueryBuilderSelect: leftJoin call #$leftJoinCallCount with args=" . json_encode($args));
                $leftJoinCallCount++;
                return $this->queryBuilder;
            });

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(4, $addSelectCallCount, "Expected four addSelect calls");
        $this->assertEquals(4, $leftJoinCallCount, "Expected four leftJoin calls");
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderCount(): void
    {
        $addSelectCallCount = 0;
        $leftJoinCallCount = 0;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->callback(function ($arg) use (&$addSelectCallCount,) {
                $addSelectExpected = ['o', 'c', 'p', 'd'];
                error_log("testCreateBaseQueryBuilderCount: addSelect call #$addSelectCallCount with arg=" . json_encode($arg));
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg, "addSelect does not match for call #$addSelectCallCount");
                $addSelectCallCount++;
                return true;
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->willReturnCallback(function (...$args) use (&$leftJoinCallCount) {
                error_log("testCreateBaseQueryBuilderCount: leftJoin call #$leftJoinCallCount with args=" . json_encode($args));
                $leftJoinCallCount++;
                return $this->queryBuilder;
            });

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(4, $addSelectCallCount, "Expected four addSelect calls");
        $this->assertEquals(4, $leftJoinCallCount, "Expected four leftJoin calls");
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByOrderId(): void
    {
        $orderId = $this->faker->uuid;
        $entity = $this->createMock($this->entityName);

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.orderId = :orderId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('orderId', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByOrderId($orderId);

        $this->assertSame($entity, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByOrderIdNotFound(): void
    {
        $orderId = $this->faker->uuid;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.orderId = :orderId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('orderId', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByOrderId($orderId);

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByOrderIdTrue(): void
    {
        $orderId = $this->faker->uuid;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.orderId = :orderId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('orderId', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByOrderId($orderId);

        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByOrderIdFalse(): void
    {
        $orderId = $this->faker->uuid;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.orderId = :orderId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('orderId', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByOrderId($orderId);

        $this->assertFalse($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithChanneledOrders(): void
    {
        $channelId = 1;
        $channelName = 'shopify';
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledOrders' => [
                [
                    'channel' => $channelId,
                    'channeledCustomer' => ['channel' => 'some_channel'],
                    'channeledProducts' => [['channel' => 'some_channel']],
                    'channeledDiscounts' => [['channel' => 'some_channel']],
                ],
            ],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledOrders' => [
                [
                    'channel' => $channelName,
                    'channeledCustomer' => [],
                    'channeledProducts' => [[]],
                    'channeledDiscounts' => [[]],
                ],
            ],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithoutChanneledOrders(): void
    {
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledOrders' => [],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledOrders' => [],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }
}