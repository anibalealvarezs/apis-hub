<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\CustomerRepository;
use stdClass;
use ReflectionProperty;

class CustomerRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|EntityManager $entityManager;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private CustomerRepository $repository;
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
        $this->queryBuilder->method('addSelect')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('leftJoin')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $this->entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = new CustomerRepository($this->entityManager, $classMetadata);
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
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledCustomers', 'c')
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
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledCustomers', 'c')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
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

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilderNoJoins');
        $result = $reflection->invoke($this->repository, QueryBuilderType::SELECT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     * @throws NonUniqueResultException
     * @throws MappingException
     */
    public function testCreateWithValidData(): void
    {
        $id = $this->faker->randomNumber();
        $data = new stdClass();
        $data->email = $this->faker->email;
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
            ->method('addSelect')
            ->with('c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledCustomers', 'c')
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
            ->willReturn(['id' => $id, 'channeledCustomers' => []]);

        $result = $this->repository->create($data);

        $this->assertIsArray($result);
        $this->assertEquals(['id' => $id, 'channeledCustomers' => []], $result);
    }

    /**
     * @throws ReflectionException
     * @throws NonUniqueResultException
     * @throws MappingException
     */
    public function testCreateWithNoEmail(): void
    {
        $data = new stdClass();
        $data->name = $this->faker->word;

        $result = $this->repository->create($data);

        $this->assertNull($result);
    }

    /**
     * @throws ReflectionException
     * @throws NonUniqueResultException
     * @throws MappingException
     */
    public function testCreateWithNullData(): void
    {
        $result = $this->repository->create();

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmail(): void
    {
        $email = $this->faker->email;
        $entity = $this->createMock($this->entityName);

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledCustomers', 'c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('email', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByEmail($email);

        $this->assertSame($entity, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmailNotFound(): void
    {
        $email = $this->faker->email;

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('addSelect')
            ->with('c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledCustomers', 'c')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('email', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByEmail($email);

        $this->assertNull($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByEmailTrue(): void
    {
        $email = $this->faker->email;

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
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('email', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByEmail($email);

        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function testExistsByEmailFalse(): void
    {
        $email = $this->faker->email;

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
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('email', $this->isType('string'))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByEmail($email);

        $this->assertFalse($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithChanneledCustomers(): void
    {
        $channelId = 1;
        $channelName = 'shopify';
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledCustomers' => [
                ['channel' => $channelId],
            ],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledCustomers' => [
                ['channel' => $channelName],
            ],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithoutChanneledCustomers(): void
    {
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledCustomers' => [],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledCustomers' => [],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }
}