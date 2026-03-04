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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\AccountRepository;

class AccountRepositoryTest extends TestCase
{
    private MockObject|EntityManager $entityManager;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private AccountRepository $repository;
    private string $entityName = 'Entities\Analytics\Account';

    protected function setUp(): void
    {
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

        $this->repository = new AccountRepository($this->entityManager, $classMetadata);
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
            ->with('a')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('leftJoin')
            ->with('e.channeledAccounts', 'a')
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

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByName(): void
    {
        $name = 'test_account';
        $this->query->method('getSingleScalarResult')->willReturn(1);

        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('name', $name)
            ->willReturnSelf();

        $result = $this->repository->existsByName($name);
        $this->assertTrue($result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByName(): void
    {
        $name = 'test_account';
        $entity = $this->createMock(Entity::class);
        $this->query->method('getOneOrNullResult')->willReturn($entity);

        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('e.name = :name')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('name', $name)
            ->willReturnSelf();

        $result = $this->repository->getByName($name);
        $this->assertSame($entity, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceChannelName(): void
    {
        $input = [
            'id' => 1,
            'name' => 'test',
            'channeledAccounts' => [
                ['id' => 1, 'channel' => 1, 'name' => 'ch_acc_1'],
                ['id' => 2, 'channel' => 2, 'name' => 'ch_acc_2'],
            ]
        ];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals('shopify', $result['channeledAccounts'][0]['channel']);
        $this->assertEquals('klaviyo', $result['channeledAccounts'][1]['channel']);
    }
}
