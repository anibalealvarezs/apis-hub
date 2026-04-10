<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\AnalyticsEntity;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Enums\JobStatus;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use Repositories\JobRepository;
use stdClass;

class JobRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private JobRepository $repository;
    private string $entityName = 'Entities\Entity';
    private MockObject|ReflectionEnum $analyticsEntitiesEnum;
    private MockObject|ReflectionEnum $channelsEnum;
    private MockObject|EntityManager $entityManager;
    private MockObject|\Doctrine\DBAL\Connection $connection;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        $this->entityManager->method('createQueryBuilder')
            ->willReturnCallback(function () {
                return $this->queryBuilder;
            });

        $this->connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->connection->method('getDatabasePlatform')->willReturn($platform);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->queryBuilder->method('select')->willReturnCallback(function ($alias) {
            return $this->queryBuilder;
        });
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
        $classMetadata->associationMappings = [];
        $classMetadata->method('getColumnNames')->willReturn([]);
        $classMetadata->name = $this->entityName;
        $this->entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->analyticsEntitiesEnum = $this->createMock(ReflectionEnum::class);
        $this->channelsEnum = $this->createMock(ReflectionEnum::class);

        $this->repository = $this->getMockBuilder(JobRepository::class)
            ->setConstructorArgs([$this->entityManager, $classMetadata])
            ->onlyMethods(['getStatusName', 'create', 'update'])
            ->getMock();

        $this->repository->method('getStatusName')
            ->willReturnCallback(function (int $status) {
                return JobStatus::from($status)->getName();
            });
        $this->repository->method('create')
            ->willReturnCallback(function (?stdClass $data = null, bool $returnEntity = false) {
                /** @var stdClass $data */
                if (!isset($data->entity) || !$data->entity) {
                    throw new InvalidArgumentException('Entity is required');
                }
                if (!$this->analyticsEntitiesEnum->getConstant($data->entity)) {
                    throw new InvalidArgumentException('Invalid entity');
                }
                if (!isset($data->channel) || !$this->channelsEnum->getConstant($data->channel)) {
                    throw new InvalidArgumentException('Invalid channel');
                }
                if (!isset($data->uuid)) {
                    $data->uuid = $this->faker->uuid;
                }
                return ['id' => 1, 'uuid' => $data->uuid];
            });
        $this->repository->method('update')
            ->willReturnCallback(function ($id, ?stdClass $data = null) {
                /** @var stdClass $data */
                if (!isset($data->status) || !$data->status) {
                    return ['id' => $id];
                }
                $data->status = JobStatus::from($data->status)->value;
                return ['id' => $id, 'status' => $data->status];
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
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

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
            ->method('from')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->never())
            ->method('leftJoin');

        $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
        $result = $reflection->invoke($this->repository, QueryBuilderType::COUNT);

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException|NonUniqueResultException
     */
    public function testCreateWithValidData(): void
    {
        $data = new stdClass();
        $data->entity = 'order';
        $data->channel = 'shopify';
        $data->uuid = $this->faker->uuid;
        $data->status = JobStatus::scheduled->value;

        $this->analyticsEntitiesEnum->expects($this->once())
            ->method('getConstant')
            ->with('order')
            ->willReturn('order');
        $this->channelsEnum->expects($this->once())
            ->method('getConstant')
            ->with('shopify')
            ->willReturn(1);

        $expectedResult = ['id' => 1, 'uuid' => $data->uuid];

        $result = $this->repository->create($data);

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException|NonUniqueResultException
     */
    public function testCreateWithMissingEntityThrowsException(): void
    {
        $data = new stdClass();
        $data->channel = 'shopify';
        $data->uuid = $this->faker->uuid;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity is required');

        $this->repository->create($data);
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     * @throws NonUniqueResultException
     */
    public function testCreateWithInvalidEntityThrowsException(): void
    {
        $data = new stdClass();
        $data->entity = 'INVALID';
        $data->channel = 'shopify';
        $data->uuid = $this->faker->uuid;
        $data->status = JobStatus::scheduled->value;

        $this->analyticsEntitiesEnum->expects($this->once())
            ->method('getConstant')
            ->with('INVALID')
            ->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid entity');

        $this->repository->create($data);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildReadMultipleQueryWithIdsAndFilters(): void
    {
        $ids = [1, 2, 3];
        $filters = (object) ['status' => JobStatus::scheduled->value, 'entity' => 'order'];
        $orderBy = 'createdAt';
        $orderDir = 'ASC';
        $limit = 10;
        $pagination = 2;
        $setParameterCallCount = 0;

        // Ensure env vars are clean to avoid "Smart Context" interfering with this specific test's expectations
        $oldSource = getenv('API_SOURCE');
        $oldEntity = getenv('API_ENTITY');
        $oldStart = getenv('START_DATE');
        $oldEnd = getenv('END_DATE');
        putenv('API_SOURCE');
        putenv('API_ENTITY');
        putenv('START_DATE');
        putenv('END_DATE');

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

        // Ensure env vars are clean to avoid "Smart Context" interfering with this specific test's expectations
        $oldSource = getenv('API_SOURCE');
        $oldEntity = getenv('API_ENTITY');
        putenv('API_SOURCE');
        putenv('API_ENTITY');

        $this->queryBuilder->expects($this->exactly(2))
            ->method('andWhere')
            ->with($this->callback(function ($condition) {
                return in_array($condition, ['e.status = :status', 'e.entity = :entity']);
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(3))
            ->method('setParameter')
            ->with($this->callback(function ($key) use (&$setParameterCallCount, $ids) {
                $expected = $setParameterCallCount === 0 ? ['ids', $ids] : (
                    $setParameterCallCount === 1 ? ['status', JobStatus::scheduled->value] : ['entity', 'order']
                );
                $this->assertEquals($expected[0], $key, "Parameter key does not match for call #$setParameterCallCount");
                $setParameterCallCount++;
                return true;
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('e.createdAt', 'ASC')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(10)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setFirstResult')
            ->with(20)
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'buildReadMultipleQuery');
        $result = $reflection->invoke($this->repository, $ids, $filters, $orderBy, $orderDir, $limit, $pagination);

        // Restore env
        if ($oldSource) putenv("API_SOURCE=$oldSource");
        if ($oldEntity) putenv("API_ENTITY=$oldEntity");
        if ($oldStart) putenv("START_DATE=$oldStart");
        if ($oldEnd) putenv("END_DATE=$oldEnd");

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(3, $setParameterCallCount, "Expected three setParameter calls");
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildReadMultipleQueryWithSmartContext(): void
    {
        // Set environment variables for isolation
        putenv('API_SOURCE=facebook');
        putenv('API_ENTITY=metric');
        putenv('START_DATE=2024-01-01');
        putenv('END_DATE=2024-01-31');

        $ids = null;
        $filters = null;
        $orderBy = 'createdAt';
        $orderDir = 'ASC';
        $limit = 10;
        $pagination = 0;

        $this->queryBuilder->expects($this->once())->method('select')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('from')->willReturnSelf();

        // In Postgres, payload LIKE filters are skipped in buildReadMultipleQuery 
        // as they are handled via Native SQL in specific methods.
        $isPostgres = \Helpers\Helpers::isPostgres();
        $expectedAndWhere = $isPostgres ? 2 : 4;
        $expectedSetParam = $isPostgres ? 2 : 6;

        $this->queryBuilder->expects($this->exactly($expectedAndWhere))
            ->method('andWhere')
            ->with($this->callback(function ($condition) {
                return in_array($condition, [
                    'e.channel = :ctx_channel',
                    'e.entity IN (:ctx_entities)',
                    '(e.payload LIKE :ctx_start_pattern1 OR e.payload LIKE :ctx_start_pattern2)',
                    '(e.payload LIKE :ctx_end_pattern1 OR e.payload LIKE :ctx_end_pattern2)'
                ]);
            }))
            ->willReturnSelf();

        $this->queryBuilder->expects($this->exactly($expectedSetParam))
            ->method('setParameter')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($this->repository, 'buildReadMultipleQuery');
        $reflection->invoke($this->repository, $ids, $filters, $orderBy, $orderDir, $limit, $pagination);

        // Clean up
        putenv('API_SOURCE');
        putenv('API_ENTITY');
        putenv('START_DATE');
        putenv('END_DATE');
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResult(): void
    {
        $data = [
            'id' => $this->faker->randomNumber(),
            'status' => JobStatus::scheduled->value,
        ];
        $expected = [
            'id' => $data['id'],
            'status' => 'scheduled',
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }

    public function testGetJobs(): void
    {
        $jobs = [
            ['id' => 1, 'status' => JobStatus::scheduled->value],
            ['id' => 2, 'status' => JobStatus::scheduled->value],
        ];
        $expected = [
            ['id' => 1, 'status' => 'scheduled'],
            ['id' => 2, 'status' => 'scheduled'],
        ];

        $this->queryBuilder->method($this->anything())->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);
        $this->query->method('getScalarResult')->willReturn(array_map(fn($j) => ['id' => $j['id']], $jobs));
        $this->query->method('getResult')->willReturn($jobs);

        $result = $this->repository->getJobs();

        $this->assertEquals($expected, $result);
    }

    public function testGetJobsByStatus(): void
    {
        $status = JobStatus::scheduled->value;
        $jobData = ['id' => 1, 'status' => $status];
        
        if (\Helpers\Helpers::isPostgres()) {
            $nativeQuery = $this->createMock(\Doctrine\ORM\NativeQuery::class);
            $nativeQuery->method('getResult')->willReturn([$jobData]);
            $nativeQuery->method('setParameters')->willReturnSelf();
            
            $this->entityManager->method('createNativeQuery')->willReturn($nativeQuery);
        } else {
            $this->queryBuilder->method($this->anything())->willReturnSelf();
            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getResult')->willReturn([$jobData]);
        }

        $result = $this->repository->getJobsByStatus($status);

        $this->assertEquals([$jobData], $result);
    }

    public function testGetJobsByUuid(): void
    {
        $uuid = $this->faker->uuid;
        $job = ['id' => 1, 'status' => JobStatus::scheduled->value];
        $expected = ['id' => 1, 'status' => 'scheduled'];

        $this->queryBuilder->method($this->anything())->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);
        $this->query->method('getScalarResult')->willReturn([['id' => 1]]);
        $this->query->method('getResult')->willReturn([$job]);

        $result = $this->repository->getJobsByUuid($uuid);

        $this->assertEquals($expected, $result);
    }

    public function testGetStatusName(): void
    {
        $status = JobStatus::scheduled->value;
        $expected = 'scheduled';

        $result = $this->repository->getStatusName($status);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testUpdateWithValidStatus(): void
    {
        $id = 1;
        $data = new stdClass();
        $data->status = JobStatus::scheduled->value;
        $expectedResult = ['id' => $id, 'status' => $data->status];

        $result = $this->repository->update($id, $data);

        $this->assertEquals($expectedResult, $result);
    }

    public function testProcessResultWithCancelledStatus(): void
    {
        $data = [
            'id' => 1,
            'status' => JobStatus::cancelled->value,
        ];
        $expected = [
            'id' => 1,
            'status' => 'cancelled',
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }

    public function testClaimJob(): void
    {
        $id = 123;
        
        $expr = $this->createMock(\Doctrine\ORM\Query\Expr::class);
        $this->queryBuilder->method('expr')->willReturn($expr);

        $this->queryBuilder->expects($this->once())
            ->method('update')
            ->with($this->entityName, 'e')
            ->willReturnSelf();
            
        $this->queryBuilder->method('set')->willReturnSelf();

        $this->queryBuilder->expects($this->exactly(4))
            ->method('setParameter')
            ->willReturnSelf();

        $this->query->expects($this->once())
            ->method('execute')
            ->willReturn(1);

        $result = $this->repository->claimJob($id);
        
        $this->assertTrue($result);
    }

    public function testHasSuccessfulRecentJob(): void
    {
        $instanceName = 'test-instance';
        
        if (\Helpers\Helpers::isPostgres()) {
            $stmt = $this->createMock(\Doctrine\DBAL\Statement::class);
            $result = $this->createMock(\Doctrine\DBAL\Result::class);
            
            $this->connection->method('prepare')->willReturn($stmt);
            $stmt->method('executeQuery')->willReturn($result);
            $result->method('fetchOne')->willReturn(1);
        } else {
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
                ->with('e.payload LIKE :instance_name_pattern')
                ->willReturnSelf();

            $this->queryBuilder->expects($this->exactly(2))
                ->method('andWhere')
                ->with($this->callback(function ($condition) {
                    return in_array($condition, ['e.status = :completed', 'e.updatedAt >= :since']);
                }))
                ->willReturnSelf();

            $this->queryBuilder->expects($this->exactly(3))
                ->method('setParameter')
                ->willReturnSelf();

            $this->query->expects($this->once())
                ->method('getSingleScalarResult')
                ->willReturn(1);
        }

        $result = $this->repository->hasSuccessfulRecentJob($instanceName);
        
        $this->assertTrue($result);
    }
}
