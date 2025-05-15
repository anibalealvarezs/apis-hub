<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Enums\QueryBuilderType;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Repositories\PriceRuleRepository;

class PriceRuleRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private PriceRuleRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $entityManager->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('addSelect')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('leftJoin')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->fieldMappings = [];
        $classMetadata->name = $this->entityName;
        $entityManager->method('getClassMetadata')
            ->with($this->entityName)
            ->willReturn($classMetadata);

        $this->repository = new PriceRuleRepository($entityManager, $classMetadata);
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
                $addSelectExpected = ['p', 'd'];
                error_log("testCreateBaseQueryBuilderSelect: addSelect call #$addSelectCallCount with arg=" . json_encode($arg));
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg, "addSelect does not match for call #$addSelectCallCount");
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
        $this->assertEquals(2, $addSelectCallCount, "Expected two addSelect calls");
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
                $addSelectExpected = ['p', 'd'];
                error_log("testCreateBaseQueryBuilderCount: addSelect call #$addSelectCallCount with arg=" . json_encode($arg));
                $this->assertEquals($addSelectExpected[$addSelectCallCount], $arg, "addSelect does not match for call #$addSelectCallCount");
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
        $this->assertEquals(2, $addSelectCallCount, "Expected two addSelect calls");
        $this->assertEquals(2, $leftJoinCallCount, "Expected two leftJoin calls");
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessResultWithChanneledPriceRules(): void
    {
        $channelId = 1;
        $channelName = 'shopify';
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledPriceRules' => [
                [
                    'channel' => $channelId,
                    'channeledDiscounts' => [['channel' => 'some_channel']],
                ],
            ],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledPriceRules' => [
                [
                    'channel' => $channelName,
                    'channeledDiscounts' => [[]],
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
    public function testProcessResultWithoutChanneledPriceRules(): void
    {
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledPriceRules' => [],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledPriceRules' => [],
        ];

        $reflection = new ReflectionMethod($this->repository, 'processResult');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceChannelName(): void
    {
        $channelId = 1;
        $channelName = 'shopify';
        $data = [
            'id' => $this->faker->randomNumber(),
            'channeledPriceRules' => [
                [
                    'channel' => $channelId,
                    'channeledDiscounts' => [['channel' => 'some_channel']],
                ],
            ],
        ];
        $expected = [
            'id' => $data['id'],
            'channeledPriceRules' => [
                [
                    'channel' => $channelName,
                    'channeledDiscounts' => [[]],
                ],
            ],
        ];

        $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
        $result = $reflection->invoke($this->repository, $data);

        $this->assertEquals($expected, $result);
    }
}