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
use Repositories\Channeled\ChanneledProductRepository;

class ChanneledProductRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledProductRepository $repository;
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
        $this->repository = new ChanneledProductRepository($entityManager, $classMetadata);
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
        $this->assertEquals(['v', 'c', 'pv'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledVendor', 'v'],
            ['e.channeledProductCategories', 'c'],
            ['e.channeledProductVariants', 'pv']
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
        $this->assertEquals(['v', 'c', 'pv'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledVendor', 'v'],
            ['e.channeledProductCategories', 'c'],
            ['e.channeledProductVariants', 'pv']
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
        $this->assertEquals(['v', 'c', 'pv'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledVendor', 'v'],
            ['e.channeledProductCategories', 'c'],
            ['e.channeledProductVariants', 'pv']
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
            'channeledVendor' => ['id' => 2, 'channel' => 1],
            'channeledProductCategories' => [
                ['id' => 3, 'channel' => 1],
                ['id' => 4, 'channel' => 1]
            ],
            'channeledProductVariants' => [
                ['id' => 5, 'channel' => 1]
            ]
        ];
        $expected = [
            'id' => 1,
            'channel' => 'shopify',
            'channeledVendor' => ['id' => 2],
            'channeledProductCategories' => [
                ['id' => 3],
                ['id' => 4]
            ],
            'channeledProductVariants' => [
                ['id' => 5]
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
        $channel = 1; // Assuming Channels::shopify->value = 1
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
        $this->assertEquals(['v', 'c', 'pv'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledVendor', 'v'],
            ['e.channeledProductCategories', 'c'],
            ['e.channeledProductVariants', 'pv']
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
        $this->assertEquals(['v', 'c', 'pv'], $addSelectCalls);
        $this->assertEquals([
            ['e.channeledVendor', 'v'],
            ['e.channeledProductCategories', 'c'],
            ['e.channeledProductVariants', 'pv']
        ], $leftJoinCalls);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuWithInvalidChannel(): void
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
    public function testExistsBySkuWithInvalidChannel(): void
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
}