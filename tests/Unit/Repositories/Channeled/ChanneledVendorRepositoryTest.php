<?php

namespace Tests\Unit\Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Repositories\Channeled\ChanneledVendorRepository;

class ChanneledVendorRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledVendorRepository $repository;
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
        $this->repository = new ChanneledVendorRepository($entityManager, $classMetadata);
        $this->faker = Factory::create();
    }

    /**
     * @throws \ReflectionException
     */
    public function testCreateBaseQueryBuilderSelect(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
    }

    /**
     * @throws \ReflectionException
     */
    public function testCreateBaseQueryBuilderCount(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
    }

    /**
     * @throws \ReflectionException
     */
    public function testCreateBaseQueryBuilderLast(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
    }

    /**
     * @throws \ReflectionException
     */
    public function testReplaceChannelName(): void
    {
        $input = [
            'id' => 1,
            'channel' => 1,
            'channeledProducts' => [
                ['id' => 2, 'channel' => 1],
                ['id' => 3, 'channel' => 1]
            ]
        ];
        $expected = [
            'id' => 1,
            'channel' => 'shopify',
            'channeledProducts' => [
                ['id' => 2],
                ['id' => 3]
            ]
        ];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByNameReturnsEntity(): void
    {
        $name = $this->faker->company;
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
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
            ->with('e.name = :name')
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

        $result = $this->repository->getByName($name, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['name', $name], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByNameReturnsNull(): void
    {
        $name = $this->faker->company;
        $channel = 1;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
            ->with('e.name = :name')
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

        $result = $this->repository->getByName($name, $channel);

        $this->assertNull($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['name', $name], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByNameWithInvalidChannelThrowsException(): void
    {
        $name = $this->faker->company;
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->getByName($name, $channel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByNameReturnsTrue(): void
    {
        $name = $this->faker->company;
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
            ->with('e.name = :name')
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->existsByName($name, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['name', $name], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByNameReturnsFalse(): void
    {
        $name = $this->faker->company;
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
            ->with('e.name = :name')
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->existsByName($name, $channel);

        $this->assertFalse($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['name', $name], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByNameWithInvalidChannelThrowsException(): void
    {
        $name = $this->faker->company;
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->existsByName($name, $channel);
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
            ->method('addSelect')
            ->with('p')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledProducts', 'p')
            ->willReturnSelf();
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
    public function testGetLastByPlatformId(): void
    {
        $channel = Channel::shopify->value;
        $result = [
            'id' => 1,
            'channel' => Channel::shopify->value,
            'platformId' => 'PR123'
        ];
        $expected = [
            'id' => 1,
            'channel' => Channel::shopify->value,
            'platformId' => 'PR123'
        ];

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
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addOrderBy')
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
    }

    public function testGetLastByPlatformCreatedAt(): void
    {
        $channel = Channel::shopify->value;
        $result = [
            'platformCreatedAt' => '2025-05-18 12:00:00'
        ];
        $expected = [
            'platformCreatedAt' => '2025-05-18 12:00:00'
        ];

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e, LENGTH(e.platformId) AS HIDDEN length')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this::once())
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
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testCountElements(): void
    {
        $filters = (object)['channel' => Channel::shopify->value];
        $result = 42;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this::never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this::once())
            ->method('setParameter')
            ->with('channel', $filters->channel)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($result);

        $actual = $this->repository->countElements($filters);
        $this::assertEquals($result, $actual);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testCountElementsWithInvalidChannelName(): void
    {
        $filters = (object)['channel' => 'invalid'];

        $this->expectException(InvalidArgumentException::class);
        $this::expectExceptionMessage('Invalid channel name: invalid');

        $this->queryBuilder->expects($this->never())
            ->method('select');
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('from');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');

        $this->repository->countElements($filters);
    }
}
