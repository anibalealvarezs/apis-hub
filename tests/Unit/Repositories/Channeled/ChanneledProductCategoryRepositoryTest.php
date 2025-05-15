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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Repositories\Channeled\ChanneledProductCategoryRepository;

class ChanneledProductCategoryRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledProductCategoryRepository $repository;
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
        $this->repository = new ChanneledProductCategoryRepository($entityManager, $classMetadata);
        $this->faker = Factory::create();
    }

    /**
     * @throws \ReflectionException
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
     * @throws \ReflectionException
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->getByPlatformId($platformId, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByPlatformIdWithInvalidChannel(): void
    {
        $platformId = $this->faker->uuid;
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->getByPlatformId($platformId, $channel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
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
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->existsByPlatformId($platformId, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['platformId', $platformId], $parameterCalls[0]);
        $this->assertEquals(['channel', $channel], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformId(): void
    {
        $channel = 1;
        $data = ['id' => 1, 'channel' => 1];
        $expected = ['id' => 1, 'channel' => 'shopify'];

        $parameterCalls = [];
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($data);
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->getLastByPlatformId($channel);

        $this->assertEquals($expected, $result);
        $this->assertCount(1, $parameterCalls);
        $this->assertEquals(['channel', $channel], $parameterCalls[0]);
        $this->assertCount(2, $orderByCalls);
        $this->assertEquals(['length', 'DESC'], $orderByCalls[0]);
        $this->assertEquals(['e.platformId', 'DESC'], $orderByCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetLastByPlatformCreatedAt(): void
    {
        $channel = 1;
        $data = ['id' => 1, 'channel' => 1, 'platformCreatedAt' => '2023-01-01'];
        $expected = ['id' => 1, 'channel' => 'shopify', 'platformCreatedAt' => '2023-01-01'];

        $parameterCalls = [];
        $this->queryBuilder->expects($this->once())
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
            ->with('e.channel = :channel')
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
            ->willReturn($data);
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->getLastByPlatformCreatedAt($channel);

        $this->assertEquals($expected, $result);
        $this->assertCount(1, $parameterCalls);
        $this->assertEquals(['channel', $channel], $parameterCalls[0]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testCountElements(): void
    {
        $filters = (object) ['channel' => 1];
        $count = $this->faker->numberBetween(1, 100);

        $parameterCalls = [];
        $this->queryBuilder->expects($this->once())
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
            ->method('andWhere')
            ->with('e.channel = :channel')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $result = $this->repository->countElements($filters);

        $this->assertEquals($count, $result);
        $this->assertCount(1, $parameterCalls);
        $this->assertEquals(['channel', $filters->channel], $parameterCalls[0]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testCountElementsWithInvalidChannelName(): void
    {
        $filters = (object) ['channel' => 'invalid'];

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
        $this->queryBuilder->expects($this->never())
            ->method('getQuery');
        $this->queryBuilder->expects($this->never())
            ->method('addSelect');
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel name: invalid');

        $this->repository->countElements($filters);
    }
}