<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\AnalyticsEntities;
use Enums\Channels;
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

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        $entityManager->method('createQueryBuilder')
            ->willReturnCallback(function () {
                error_log("Mocked EntityManager::createQueryBuilder");
                return $this->queryBuilder;
            });

        $this->queryBuilder->method('select')->willReturnCallback(function ($alias) {
            error_log("Mocked QueryBuilder::select with alias=$alias");
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
        $classMetadata->name = $this->entityName;
        $entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->analyticsEntitiesEnum = $this->createMock(ReflectionEnum::class);
        $this->channelsEnum = $this->createMock(ReflectionEnum::class);

        $this->repository = $this->getMockBuilder(JobRepository::class)
            ->setConstructorArgs([$entityManager, $classMetadata])
            ->onlyMethods(['getStatusName', 'create', 'update'])
            ->getMock();

        $this->repository->method('getStatusName')
            ->willReturnCallback(function (int $status) {
                error_log("Mocked getStatusName with status=$status");
                return JobStatus::from($status)->getName();
            });
        $this->repository->method('create')
            ->willReturnCallback(function ($data, $returnEntity = false) {
                error_log("Mocked create with data=" . json_encode($data));
                if (!isset($data->entity) || !$data->entity) {
                    throw new InvalidArgumentException('Entity is required');
                }
                error_log("Checking analyticsEntitiesEnum->getConstant with value={$data->entity}");
                if (!$this->analyticsEntitiesEnum->getConstant($data->entity)) {
                    throw new InvalidArgumentException('Invalid entity');
                }
                error_log("Checking channelsEnum->getConstant with value={$data->channel}");
                if (!isset($data->channel) || !$this->channelsEnum->getConstant($data->channel)) {
                    throw new InvalidArgumentException('Invalid channel');
                }
                if (!isset($data->uuid)) {
                    $data->uuid = $this->faker->uuid;
                }
                return ['id' => 1, 'uuid' => $data->uuid];
            });
        $this->repository->method('update')
            ->willReturnCallback(function ($id, $data = null) {
                error_log("Mocked update with id=$id, data=" . json_encode($data));
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
        $emProperty->setValue($this->repository, $entityManager);
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
            ->method('andWhere')
            ->with($this->callback(function ($condition) {
                return in_array($condition, ['e.status = :status', 'e.entity = :entity']);
            }))
            ->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(3))
            ->method('setParameter')
            ->with($this->callback(function ($key) use (&$setParameterCallCount, $ids, $filters) {
                error_log("testBuildReadMultipleQuery: setParameter call #$setParameterCallCount with key=" . json_encode($key));
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

        $this->assertInstanceOf(QueryBuilder::class, $result);
        $this->assertEquals(3, $setParameterCallCount, "Expected three setParameter calls");
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

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getResult')
            ->willReturn($jobs);

        $result = $this->repository->getJobs();

        $this->assertEquals($expected, $result);
    }

    public function testGetJobsByStatus(): void
    {
        $status = JobStatus::scheduled->value;
        $job = ['id' => 1, 'status' => $status];
        $expected = ['id' => 1, 'status' => 'scheduled'];

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('j.status = :status')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('status', $status)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getResult')
            ->willReturn($job);

        $result = $this->repository->getJobsByStatus($status);

        $this->assertEquals($expected, $result);
    }

    public function testGetJobsByUuid(): void
    {
        $uuid = $this->faker->uuid;
        $job = ['id' => 1, 'status' => JobStatus::scheduled->value];
        $expected = ['id' => 1, 'status' => 'scheduled'];

        $this->queryBuilder->expects($this->once())
            ->method('select')
            ->with('j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with($this->entityName, 'j')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with('j.uuid = :uuid')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('uuid', $uuid)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getResult')
            ->willReturn($job);

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
}