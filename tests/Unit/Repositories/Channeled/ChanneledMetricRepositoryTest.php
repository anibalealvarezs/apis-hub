<?php

namespace Tests\Unit\Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Analytics\Metric;
use Enums\QueryBuilderType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\Channeled\ChanneledMetricRepository;

class ChanneledMetricRepositoryTest extends TestCase
{
    private MockObject|EntityManager $entityManager;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledMetricRepository $repository;
    private string $entityName = 'Entities\Analytics\Channeled\ChanneledMetric';

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
        $this->queryBuilder->method('join')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $this->entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = new ChanneledMetricRepository($this->entityManager, $classMetadata);
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
        $this->queryBuilder->expects($this->exactly(3))
            ->method('addSelect')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(3))
            ->method('leftJoin')
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
    public function testExistsByPlatformIdAndMetric(): void
    {
        $platformId = 'test_id';
        $channel = 1;
        $metric = $this->createMock(Metric::class);

        $this->query->method('getSingleScalarResult')->willReturn(1);

        $result = $this->repository->existsByPlatformIdAndMetric($platformId, $channel, $metric);
        $this->assertTrue($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultIncludeRawData(): void
    {
        $this->repository->setIncludeRawData(true);

        $input = [
            'id' => 1,
            'channel' => 1,
            'data' => ['raw' => 'data'],
            'metricDate' => new \DateTime('2026-03-03'),
            'metric' => [
                'id' => 2,
                'value' => 10,
                'metricConfig' => [
                    'name' => 'test'
                ]
            ]
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($this->repository, $input);

        $this->assertEquals('shopify', $result['channel']);
        $this->assertEquals('2026-03-03', $result['metricDate']);
        $this->assertEquals('test', $result['name']);
        $this->assertEquals(2, $result['metricId']);
        $this->assertEquals(10, $result['value']);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultExcludeRawData(): void
    {
        $this->repository->setIncludeRawData(false);

        $input = [
            'id' => 1,
            'channel' => 1,
            'data' => ['raw' => 'data'],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($this->repository, $input);

        $this->assertArrayNotHasKey('data', $result);
    }
}
