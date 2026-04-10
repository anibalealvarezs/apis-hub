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
use ReflectionException;
use ReflectionMethod;
use Repositories\Channeled\ChanneledProductVariantRepository;

class ChanneledProductVariantRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledProductVariantRepository $repository;
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
        $this->repository = new ChanneledProductVariantRepository($entityManager, $classMetadata);
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
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
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
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
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
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
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
            'channeledProduct' => [
                'id' => 2,
                'channel' => 1,
                'channeledVendor' => ['id' => 3, 'channel' => 1],
                'channeledProductCategories' => [
                    ['id' => 4, 'channel' => 1],
                    ['id' => 5, 'channel' => 1]
                ]
            ]
        ];
        $expected = [
            'id' => 1,
            'channel' => 'shopify',
            'channeledProduct' => [
                'id' => 2,
                'channeledVendor' => ['id' => 3],
                'channeledProductCategories' => [
                    ['id' => 4],
                    ['id' => 5]
                ]
            ]
        ];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuReturnsEntity(): void
    {
        $sku = $this->faker->word;
        $channel = 1;
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
            ->with('e.sku = :sku')
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

        $result = $this->repository->getBySku($sku, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['sku', $sku], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
        ], $leftJoinCalls);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuReturnsNull(): void
    {
        $sku = $this->faker->word;
        $channel = 1;

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
            ->with('e.sku = :sku')
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

        $result = $this->repository->getBySku($sku, $channel);

        $this->assertNull($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['sku', $sku], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
        ], $leftJoinCalls);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuWithInvalidChannelThrowsException(): void
    {
        $sku = $this->faker->word;
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->getBySku($sku, $channel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsBySkuReturnsTrue(): void
    {
        $sku = $this->faker->word;
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
            ->with('e.sku = :sku')
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

        $result = $this->repository->existsBySku($sku, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['sku', $sku], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsBySkuReturnsFalse(): void
    {
        $sku = $this->faker->word;
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
            ->with('e.sku = :sku')
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

        $result = $this->repository->existsBySku($sku, $channel);

        $this->assertFalse($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['sku', $sku], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsBySkuWithInvalidChannelThrowsException(): void
    {
        $sku = $this->faker->word;
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->existsBySku($sku, $channel);
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

        $result = $this->repository->getByPlatformId($platformId, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
        $this->assertEquals(['p', 'v', 'c'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledProduct', 'p'],
            ['p.channeledVendor', 'v'],
            ['p.channeledProductCategories', 'c']
        ], $leftJoinCalls);
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

    /**
     * @throws NonUniqueResultException
     */
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
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
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
        $this->assertEquals($result, $actual);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testCountElementsWithInvalidChannelName(): void
    {
        $filters = (object)['channel' => 'invalid'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel name: invalid');

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
