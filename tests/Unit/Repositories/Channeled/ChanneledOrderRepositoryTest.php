<?php

namespace Tests\Unit\Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Repositories\Channeled\ChanneledOrderRepository;
use TypeError;

class ChanneledOrderRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledOrderRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);
        $this->entityName = 'Entities\Entity';
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = $this->entityName;
        $entityManager->expects($this->any())
            ->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);
        $entityManager->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);
        $this->repository = new ChanneledOrderRepository($entityManager, $classMetadata);
        $this->faker = Factory::create();
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderSelect(): void
    {
        $addSelectCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnCallback(function ($alias) use (&$addSelectCalls) {
                $addSelectCalls[] = $alias;
                return $this->queryBuilder;
            });

        $leftJoinCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use (&$leftJoinCalls) {
                $leftJoinCalls[] = [$join, $alias];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(['c', 'p', 'd'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledCustomer', 'c'],
            ['e.channeledProducts', 'p'],
            ['e.channeledDiscounts', 'd']
        ], $leftJoinCalls);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderCount(): void
    {
        $addSelectCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnCallback(function ($alias) use (&$addSelectCalls) {
                $addSelectCalls[] = $alias;
                return $this->queryBuilder;
            });

        $leftJoinCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use (&$leftJoinCalls) {
                $leftJoinCalls[] = [$join, $alias];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(['c', 'p', 'd'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledCustomer', 'c'],
            ['e.channeledProducts', 'p'],
            ['e.channeledDiscounts', 'd']
        ], $leftJoinCalls);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderLast(): void
    {
        $addSelectCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnCallback(function ($alias) use (&$addSelectCalls) {
                $addSelectCalls[] = $alias;
                return $this->queryBuilder;
            });

        $leftJoinCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use (&$leftJoinCalls) {
                $leftJoinCalls[] = [$join, $alias];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e, LENGTH(e.platformId) AS HIDDEN length')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::LAST);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(['c', 'p', 'd'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledCustomer', 'c'],
            ['e.channeledProducts', 'p'],
            ['e.channeledDiscounts', 'd']
        ], $leftJoinCalls);
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceChannelName(): void
    {
        $input = [
            'id' => 1,
            'channel' => 1,
            'channeledCustomer' => ['id' => 2, 'channel' => 1],
            'channeledProducts' => [
                ['id' => 3, 'channel' => 1],
                ['id' => 4, 'channel' => 1]
            ],
            'channeledDiscounts' => [
                ['id' => 5, 'channel' => 1]
            ]
        ];
        $expected = [
            'id' => 1,
            'channel' => 'shopify',
            'channeledCustomer' => ['id' => 2],
            'channeledProducts' => [
                ['id' => 3, 'channel' => 'shopify'],
                ['id' => 4, 'channel' => 'shopify']
            ],
            'channeledDiscounts' => [
                ['id' => 5, 'channel' => 'shopify']
            ]
        ];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByOrderIdReturnsEntity(): void
    {
        $orderId = $this->faker->uuid;
        $channel = Channels::shopify;
        $entity = new Entity();

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

        $addSelectCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnCallback(function ($alias) use (&$addSelectCalls) {
                $addSelectCalls[] = $alias;
                return $this->queryBuilder;
            });

        $leftJoinCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use (&$leftJoinCalls) {
                $leftJoinCalls[] = [$join, $alias];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.platformId = :platformId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByOrderId($orderId, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $orderId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel->value], $parameterCalls[1]);
        $this->assertEquals(['c', 'p', 'd'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledCustomer', 'c'],
            ['e.channeledProducts', 'p'],
            ['e.channeledDiscounts', 'd']
        ], $leftJoinCalls);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByOrderIdReturnsNull(): void
    {
        $orderId = $this->faker->uuid;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

        $addSelectCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnCallback(function ($alias) use (&$addSelectCalls) {
                $addSelectCalls[] = $alias;
                return $this->queryBuilder;
            });

        $leftJoinCalls = [];
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use (&$leftJoinCalls) {
                $leftJoinCalls[] = [$join, $alias];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.platformId = :platformId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByOrderId($orderId, $channel);

        $this->assertNull($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $orderId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel->value], $parameterCalls[1]);
        $this->assertEquals(['c', 'p', 'd'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledCustomer', 'c'],
            ['e.channeledProducts', 'p'],
            ['e.channeledDiscounts', 'd']
        ], $leftJoinCalls);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByOrderIdWithInvalidChannel(): void
    {
        $orderId = $this->faker->uuid;
        $channel = 999;

        $this->queryBuilder->expects($this->never())
            ->method('select');
        $this->queryBuilder->expects($this->never())
            ->method('from');
        $this->queryBuilder->expects($this->never())
            ->method('where');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/Argument #2 \(\$channel\) must be of type Enums\\\Channels, int given/');

        $this->repository->getByOrderId($orderId, $channel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByOrderIdReturnsTrue(): void
    {
        $orderId = $this->faker->uuid;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.platformId = :platformId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByOrderId($orderId, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $orderId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByOrderIdReturnsFalse(): void
    {
        $orderId = $this->faker->uuid;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.platformId = :platformId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByOrderId($orderId, $channel);

        $this->assertFalse($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $orderId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByOrderIdWithInvalidChannel(): void
    {
        $orderId = $this->faker->uuid;
        $channel = 999;

        $this->queryBuilder->expects($this->never())
            ->method('select');
        $this->queryBuilder->expects($this->never())
            ->method('from');
        $this->queryBuilder->expects($this->never())
            ->method('where');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/Argument #2 \(\$channel\) must be of type Enums\\\Channels, int given/');

        $this->repository->existsByOrderId($orderId, $channel);
    }
}