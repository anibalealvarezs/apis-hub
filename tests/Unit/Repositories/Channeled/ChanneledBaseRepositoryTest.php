<?php

namespace Tests\Unit\Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Repositories\Channeled\ChanneledBaseRepository;

class ChanneledBaseRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledBaseRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);
        $this->entityName = 'Entities\Entity'; // Updated to match import
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = $this->entityName;
        $entityManager->expects($this->any())
            ->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);
        $entityManager->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder); // Added to return mocked QueryBuilder
        $this->repository = new ChanneledBaseRepository($entityManager, $classMetadata);
        $this->faker = Factory::create();
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderSelect(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderCount(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderLast(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e, LENGTH(e.platformId) AS HIDDEN length')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::LAST);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderNoJoinsSelect(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilderNoJoins');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderNoJoinsCount(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilderNoJoins');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderNoJoinsLast(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e, LENGTH(e.platformId) AS HIDDEN length')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilderNoJoins');
        $result = $reflection->invoke($this->repository, QueryBuilderType::LAST);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultReplacesChannelName(): void
    {
        $input = ['id' => 1, 'channel' => 1];
        $expected = ['id' => 1, 'channel' => 'shopify'];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceChannelName(): void
    {
        $input = ['id' => 1, 'channel' => 1];
        $expected = ['id' => 1, 'channel' => 'shopify'];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByPlatformIdReturnsEntity(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 1;
        $entity = new Entity();

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
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

        $result = $this->repository->getByPlatformId($platformId, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByPlatformIdReturnsNull(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 1;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
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

        $result = $this->repository->getByPlatformId($platformId, $channel);

        $this->assertNull($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByPlatformIdInvalidChannel(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 999;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->getByPlatformId($platformId, $channel);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByPlatformIdReturnsTrue(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 1;

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

        $result = $this->repository->existsByPlatformId($platformId, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByPlatformIdReturnsFalse(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 1;

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

        $result = $this->repository->existsByPlatformId($platformId, $channel);

        $this->assertFalse($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByPlatformIdInvalidChannel(): void
    {
        $platformId = $this->faker->uuid;
        $channel = 999;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->existsByPlatformId($platformId, $channel);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsNoFilters(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(42);

        $result = $this->repository->countElements();

        $this->assertSame(42, $result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsWithFilters(): void
    {
        $filters = (object) ['platformId' => $this->faker->uuid, 'channel' => 'shopify'];
        $count = 42;

        $andWhereCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function ($condition) use (&$andWhereCalls) {
                $andWhereCalls[] = $condition;
                return $this->queryBuilder;
            });

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
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);

        $result = $this->repository->countElements($filters);

        $this->assertSame($count, $result);
        $this->assertCount(2, $andWhereCalls);
        $this->assertEquals('e.platformId = :platformId', $andWhereCalls[0]);
        $this->assertEquals('e.channel = :channel', $andWhereCalls[1]);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $filters->platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', 1], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsWithNumericChannelFilter(): void
    {
        $filters = (object) ['platformId' => $this->faker->uuid, 'channel' => 1];
        $count = 42;

        $andWhereCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function ($condition) use (&$andWhereCalls) {
                $andWhereCalls[] = $condition;
                return $this->queryBuilder;
            });

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
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);

        $result = $this->repository->countElements($filters);

        $this->assertSame($count, $result);
        $this->assertCount(2, $andWhereCalls);
        $this->assertEquals('e.platformId = :platformId', $andWhereCalls[0]);
        $this->assertEquals('e.channel = :channel', $andWhereCalls[1]);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $filters->platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $filters->channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsWithInvalidChannelFilter(): void
    {
        $filters = (object) ['platformId' => $this->faker->uuid, 'channel' => 'INVALID_CHANNEL'];

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->atMost(1))
            ->method('andWhere')
            ->with('e.platformId = :platformId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->atMost(1))
            ->method('setParameter')
            ->with('platformId', $filters->platformId)
            ->willReturnSelf();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel name: INVALID_CHANNEL');

        $this->repository->countElements($filters);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsThrowsNoResultException(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NoResultException());

        $this->expectException(NoResultException::class);

        $this->repository->countElements();
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testCountElementsThrowsNonUniqueResultException(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NonUniqueResultException());

        $this->expectException(NonUniqueResultException::class);

        $this->repository->countElements();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformIdReturnsArray(): void
    {
        $channel = 1;
        $result = ['id' => 1, 'channel' => 1];
        $expected = ['id' => 1, 'channel' => 'shopify'];

        $orderByCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addOrderBy')
            ->willReturnCallback(function ($sort, $order) use (&$orderByCalls) {
                $orderByCalls[] = [$sort, $order];
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
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('channel', $channel)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($result);

        $actual = $this->repository->getLastByPlatformId($channel);

        $this->assertEquals($expected, $actual);
        $this->assertCount(2, $orderByCalls);
        $this->assertEquals(['length', 'DESC'], $orderByCalls[0]);
        $this->assertEquals(['e.platformId', 'DESC'], $orderByCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformIdReturnsNull(): void
    {
        $channel = 1;

        $orderByCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addOrderBy')
            ->willReturnCallback(function ($sort, $order) use (&$orderByCalls) {
                $orderByCalls[] = [$sort, $order];
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
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('channel', $channel)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(null);

        $result = $this->repository->getLastByPlatformId($channel);

        $this->assertNull($result);
        $this->assertCount(2, $orderByCalls);
        $this->assertEquals(['length', 'DESC'], $orderByCalls[0]);
        $this->assertEquals(['e.platformId', 'DESC'], $orderByCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformCreatedAtReturnsArray(): void
    {
        $channel = 1;
        $result = ['id' => 1, 'platformCreatedAt' => '2023-01-01', 'channel' => 1];
        $expected = ['id' => 1, 'platformCreatedAt' => '2023-01-01', 'channel' => 'shopify'];

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
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('channel', $channel)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('addOrderBy')
            ->with('e.platformCreatedAt', 'DESC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($result);

        $actual = $this->repository->getLastByPlatformCreatedAt($channel);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformCreatedAtReturnsNull(): void
    {
        $channel = 1;

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
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('channel', $channel)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('addOrderBy')
            ->with('e.platformCreatedAt', 'DESC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(null);

        $result = $this->repository->getLastByPlatformCreatedAt($channel);

        $this->assertNull($result);
    }
}