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
use Repositories\DiscountRepository;

class DiscountRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private DiscountRepository $repository;
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

        $this->repository = new DiscountRepository($entityManager, $classMetadata);
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
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addSelect')
            ->with($this->callback(function ($arg) use (&$addSelectCallCount,) {
                $addSelectExpected = ['d', 'pr'];
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg);
                $addSelectCallCount++;
                return true;
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->willReturnCallback(function (...$args) use (&$leftJoinCallCount) {
                error_log("testCreateBaseQueryBuilderSelect: leftJoin call #$leftJoinCallCount with args=" . json_encode($args));
                $leftJoinCallCount++;
                return $this->queryBuilder;
            });

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(2, $leftJoinCallCount, "Expected two leftJoin calls");
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
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addSelect')
            ->with($this->callback(function ($arg) use (&$addSelectCallCount,) {
                $addSelectExpected = ['d', 'pr'];
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg);
                $addSelectCallCount++;
                return true;
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->willReturnCallback(function (...$args) use (&$leftJoinCallCount) {
                error_log("testCreateBaseQueryBuilderCount: leftJoin call #$leftJoinCallCount with args=" . json_encode($args));
                $leftJoinCallCount++;
                return $this->queryBuilder;
            });

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(2, $leftJoinCallCount, "Expected two leftJoin calls");
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateBaseQueryBuilderNoJoins(): void
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
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilderNoJoins');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByCode(): void
    {
        $code = $this->faker->word;
        $entity = $this->createMock($this->entityName);

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addSelect')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.code = :code')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('code', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByCode($code);

        $this->assertSame($entity, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByCodeNotFound(): void
    {
        $code = $this->faker->word;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('addSelect')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.code = :code')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('code', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByCode($code);

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByCodeTrue(): void
    {
        $code = $this->faker->word;

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
            ->with('e.code = :code')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('code', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByCode($code);

        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByCodeFalse(): void
    {
        $code = $this->faker->word;

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
            ->with('e.code = :code')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('code', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByCode($code);

        $this->assertFalse($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithChanneledDiscounts(): void
    {
        $channelId = 1;
        $channelName = 'shopify';
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledDiscounts' => [
                [
                    'channel' => $channelId,
                    'channeledPriceRule' => ['channel' => 'some_channel'],
                ],
            ],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledDiscounts' => [
                [
                    'channel' => $channelName,
                    'channeledPriceRule' => [],
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
    public function testProcessResultWithoutChanneledDiscounts(): void
    {
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledDiscounts' => [],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledDiscounts' => [],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }
}