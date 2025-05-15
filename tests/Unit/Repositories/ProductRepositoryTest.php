<?php

namespace Tests\Unit\Repositories;

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
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\ProductRepository;

class ProductRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ProductRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        $this->entityManager->method('createQueryBuilder')
            ->willReturnCallback(function () {
                error_log("Mocked EntityManager::createQueryBuilder");
                return $this->queryBuilder;
            });

        $this->queryBuilder->method('select')->willReturnCallback(function ($alias) {
            error_log("Mocked QueryBuilder::select with alias=$alias");
            return $this->queryBuilder;
        });
        $this->queryBuilder->method('addSelect')->willReturnCallback(function ($select) {
            error_log("Mocked QueryBuilder::addSelect with select=$select");
            return $this->queryBuilder;
        });
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('leftJoin')->willReturnCallback(function ($join, $alias, $conditionType, $condition, $indexBy) {
            error_log("Mocked QueryBuilder::leftJoin with args=[" . json_encode($join) . ",$alias,$conditionType,$condition,$indexBy]");
            return $this->queryBuilder;
        });
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnCallback(function ($key, $value) {
            error_log("Mocked QueryBuilder::setParameter with key=$key, value=" . json_encode($value));
            return $this->queryBuilder;
        });
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $this->entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = $this->getMockBuilder(ProductRepository::class)
            ->setConstructorArgs([$this->entityManager, $classMetadata])
            ->onlyMethods(['createBaseQueryBuilderNoJoins', 'create', 'update'])
            ->getMock();

        $this->repository->method('createBaseQueryBuilderNoJoins')
            ->willReturnCallback(function ($type) {
                error_log("Mocked createBaseQueryBuilderNoJoins with type=" . $type->name);
                $query = $this->entityManager->createQueryBuilder();
                match ($type) {
                    QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
                    QueryBuilderType::COUNT => $query->select('count(e.id)'),
                };
                return $query->from($this->entityName, 'e');
            });
        $this->repository->method('create')
            ->willReturnCallback(function ($data) {
                error_log("Mocked create with data=" . json_encode($data));
                return ['id' => 1, 'productId' => $data->productId ?? $this->faker->uuid];
            });
        $this->repository->method('update')
            ->willReturnCallback(function ($id, $data) {
                error_log("Mocked update with id=$id, data=" . json_encode($data));
                return ['id' => $id];
            });

        $reflection = new ReflectionClass($this->repository);
        $entityNameProperty = $reflection->getProperty('_entityName');
        $entityNameProperty->setValue($this->repository, $this->entityName);
        $emProperty = $reflection->getProperty('_em');
        $emProperty->setValue($this->repository, $this->entityManager);
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
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();

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
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultReplacesChannelName(): void
    {
        $input = [
            'id' => 1,
            'channeledProducts' => [
                [
                    'channel' => 1,
                    'channeledVendor' => ['channel' => 1],
                    'channeledProductCategories' => [['channel' => 1]],
                    'channeledProductVariants' => [['channel' => 1]]
                ]
            ]
        ];
        $expected = [
            'id' => 1,
            'channeledProducts' => [
                [
                    'channel' => 'shopify',
                    'channeledVendor' => [],
                    'channeledProductCategories' => [[]],
                    'channeledProductVariants' => [[]]
                ]
            ]
        ];

        error_log("testProcessResultReplacesChannelName: Input=" . json_encode($input));

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $input);

        error_log("testProcessResultReplacesChannelName: Output=" . json_encode($result));

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByProductIdReturnsEntity(): void
    {
        $productId = $this->faker->uuid;
        $entity = new Entity();

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByProductId($productId);

        $this->assertSame($entity, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByProductIdReturnsNull(): void
    {
        $productId = $this->faker->uuid;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByProductId($productId);

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByProductIdReturnsTrue(): void
    {
        $productId = $this->faker->uuid;

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
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByProductId($productId);

        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByProductIdReturnsFalse(): void
    {
        $productId = $this->faker->uuid;

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
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByProductId($productId);

        $this->assertFalse($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByProductIdThrowsNonUniqueResultException(): void
    {
        $productId = $this->faker->uuid;

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
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NonUniqueResultException());

        $this->expectException(NonUniqueResultException::class);

        $this->repository->existsByProductId($productId);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByProductIdThrowsNoResultException(): void
    {
        $productId = $this->faker->uuid;

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
            ->with('e.productId = :productId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('productId', $productId)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NoResultException());

        $this->expectException(NoResultException::class);

        $this->repository->existsByProductId($productId);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuReturnsEntity(): void
    {
        $sku = $this->faker->word;
        $entity = new Entity();

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getBySku($sku);

        $this->assertSame($entity, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetBySkuReturnsNull(): void
    {
        $sku = $this->faker->word;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->with($this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getBySku($sku);

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsBySkuReturnsTrue(): void
    {
        $sku = $this->faker->word;

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
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsBySku($sku);

        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsBySkuReturnsFalse(): void
    {
        $sku = $this->faker->word;

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
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsBySku($sku);

        $this->assertFalse($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsBySkuThrowsNonUniqueResultException(): void
    {
        $sku = $this->faker->word;

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
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NonUniqueResultException());

        $this->expectException(NonUniqueResultException::class);

        $this->repository->existsBySku($sku);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsBySkuThrowsNoResultException(): void
    {
        $sku = $this->faker->word;

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
            ->with('e.sku = :sku')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('sku', $sku)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willThrowException(new NoResultException());

        $this->expectException(NoResultException::class);

        $this->repository->existsBySku($sku);
    }
}