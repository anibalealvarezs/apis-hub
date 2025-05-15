<?php

namespace Tests\Unit\Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Repositories\BaseRepository;
use stdClass;
use ReflectionProperty;

class BaseRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ConcreteBaseRepository $repository;
    private string $entityName = 'Entities\Entity';
    private Entity $persistedEntity;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        $this->entityManager->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('setFirstResult')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $this->entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = new ConcreteBaseRepository($this->entityManager, $classMetadata);
    }

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

        $result = $this->repository->createBaseQueryBuilder();

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

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

        $result = $this->repository->createBaseQueryBuilder(QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

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

        $result = $this->repository->createBaseQueryBuilderNoJoins();

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testCreateWithData(): void
    {
        $id = $this->faker->randomNumber();
        $data = new stdClass();
        $data->name = $this->faker->word;

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf($this->entityName))
            ->willReturnCallback(function ($entity) {
                $this->persistedEntity = $entity;
            });
        $this->entityManager->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use ($id) {
                $reflection = new ReflectionProperty($this->entityName, 'id');
                $reflection->setValue($this->persistedEntity, $id);
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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(['id' => $id]);

        $result = $this->repository->create($data);

        $this->assertIsArray($result);
        $this->assertEquals(['id' => $id], $result);
    }

    public function testCreateWithNoData(): void
    {
        $id = $this->faker->randomNumber();

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf($this->entityName))
            ->willReturnCallback(function ($entity) {
                $this->persistedEntity = $entity;
            });
        $this->entityManager->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use ($id) {
                $reflection = new ReflectionProperty($this->entityName, 'id');
                $reflection->setValue($this->persistedEntity, $id);
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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(['id' => $id]);

        $result = $this->repository->create();

        $this->assertIsArray($result);
        $this->assertEquals(['id' => $id], $result);
    }

    public function testReadWithEntityReturn(): void
    {
        $id = $this->faker->randomNumber();
        $entity = $this->createMock($this->entityName);

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->read($id, true);

        $this->assertSame($entity, $result);
    }

    public function testReadWithArrayReturn(): void
    {
        $id = $this->faker->randomNumber();
        $data = ['id' => $id];

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($data);

        $result = $this->repository->read($id);

        $this->assertIsArray($result);
        $this->assertEquals($data, $result);
    }

    public function testReadWithFilters(): void
    {
        $id = $this->faker->randomNumber();
        $filters = (object) ['name' => $this->faker->word];
        $data = ['id' => $id];

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($data);

        $result = $this->repository->read($id, false, $filters);

        $this->assertIsArray($result);
        $this->assertEquals($data, $result);
    }

    public function testReadReturnsNullWhenNoResult(): void
    {
        $id = $this->faker->randomNumber();

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(null);

        $result = $this->repository->read($id);

        $this->assertNull($result);
    }

    public function testBuildReadQueryWithoutFilters(): void
    {
        $id = $this->faker->randomNumber();

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();

        $result = $this->repository->buildReadQuery($id);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testBuildReadQueryWithFilters(): void
    {
        $id = $this->faker->randomNumber();
        $filters = (object) ['name' => $this->faker->word];

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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.name = :name')
            ->willReturnSelf();

        $result = $this->repository->buildReadQuery($id, $filters);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testGetCount(): void
    {
        $count = $this->faker->numberBetween(0, 100);

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);

        $result = $this->repository->getCount();

        $this->assertEquals($count, $result);
    }

    public function testCountElementsWithoutFilters(): void
    {
        $count = $this->faker->numberBetween(0, 100);

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('count(e.id)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);

        $result = $this->repository->countElements();

        $this->assertEquals($count, $result);
    }

    public function testCountElementsWithFilters(): void
    {
        $count = $this->faker->numberBetween(0, 100);
        $filters = (object) ['name' => $this->faker->word];

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
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('name', $filters->name)
            ->willReturnSelf();
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn($count);

        $result = $this->repository->countElements($filters);

        $this->assertEquals($count, $result);
    }

    public function testReadMultipleWithIds(): void
    {
        $limit = 10;
        $pagination = 0;
        $ids = [$this->faker->randomNumber(), $this->faker->randomNumber()];
        $data = [
            ['id' => $ids[0]],
            ['id' => $ids[1]],
        ];

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
            ->with('e.id IN (:ids)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('ids', $ids)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('e.id', 'DESC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with($limit)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setFirstResult')
            ->with($limit * $pagination)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($data);

        $result = $this->repository->readMultiple($limit, $pagination, $ids);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertEquals($data, $result->toArray());
    }

    public function testReadMultipleWithFilters(): void
    {
        $limit = 10;
        $pagination = 0;
        $filters = (object) ['name' => $this->faker->word];
        $data = [['id' => $this->faker->randomNumber()]];

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('name', $filters->name)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('e.id', 'DESC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with($limit)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setFirstResult')
            ->with($limit * $pagination)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn($data);

        $result = $this->repository->readMultiple($limit, $pagination, null, $filters);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertEquals($data, $result->toArray());
    }

    public function testBuildReadMultipleQueryWithIdsAndFilters(): void
    {
        $limit = 10;
        $pagination = 0;
        $ids = [$this->faker->randomNumber()];
        $filters = (object) ['name' => $this->faker->word];
        $orderBy = 'id';
        $orderDir = 'ASC';

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
            ->with('e.id IN (:ids)')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('e.id', 'ASC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with($limit)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setFirstResult')
            ->with($limit * $pagination)
            ->willReturnSelf();

        $result = $this->repository->buildReadMultipleQuery($ids, $filters, $orderBy, $orderDir, $limit, $pagination);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testProcessResult(): void
    {
        $data = ['id' => $this->faker->randomNumber()];

        $result = $this->repository->processResult($data);

        $this->assertEquals($data, $result);
    }

    public function testUpdateWithData(): void
    {
        $id = $this->faker->randomNumber();
        $data = new stdClass();
        $data->name = $this->faker->word;

        $entity = $this->getMockBuilder($this->entityName)
            ->setConstructorArgs([])
            ->onlyMethods(['getId', 'onPreUpdate'])
            ->addMethods(['addName'])
            ->getMock();
        $entity->method('getId')->willReturn($id);
        $entity->method('addName')->willReturnSelf();
        $entity->method('onPreUpdate')->willReturnSelf();

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with($this->entityName, $id)
            ->willReturn($entity);
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($entity);
        $this->entityManager->expects($this->once())
            ->method('flush');
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
            ->with('e.id = :id')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('id', $id)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_ARRAY)
            ->willReturn(['id' => $id]);

        $result = $this->repository->update($id, $data);

        $this->assertIsArray($result);
        $this->assertEquals(['id' => $id], $result);
    }

    public function testUpdateWithNoEntity(): void
    {
        $id = $this->faker->randomNumber();
        $data = new stdClass();

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with($this->entityName, $id)
            ->willReturn(null);

        $result = $this->repository->update($id, $data);

        $this->assertFalse($result);
    }

    public function testDeleteWithEntity(): void
    {
        $id = $this->faker->randomNumber();
        $entity = $this->createMock($this->entityName);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with($this->entityName, $id)
            ->willReturn($entity);
        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($entity);
        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->repository->delete($id);

        $this->assertTrue($result);
    }

    public function testDeleteWithNoEntity(): void
    {
        $id = $this->faker->randomNumber();

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with($this->entityName, $id)
            ->willReturn(null);

        $result = $this->repository->delete($id);

        $this->assertFalse($result);
    }
}

class ConcreteBaseRepository extends BaseRepository
{
    public function __construct(EntityManager $em, ClassMetadata $class)
    {
        parent::__construct($em, $class);
    }

    public function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        return parent::createBaseQueryBuilder($type);
    }

    public function createBaseQueryBuilderNoJoins(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        return parent::createBaseQueryBuilderNoJoins($type);
    }

    public function buildReadQuery(int $id, ?object $filters = null): QueryBuilder
    {
        return parent::buildReadQuery($id, $filters);
    }

    public function buildReadMultipleQuery(
        ?array $ids,
        ?object $filters,
        string $orderBy,
        string $orderDir,
        int $limit,
        int $pagination
    ): QueryBuilder {
        return parent::buildReadMultipleQuery($ids, $filters, $orderBy, $orderDir, $limit, $pagination);
    }

    public function processResult(array $result): array
    {
        return parent::processResult($result);
    }
}