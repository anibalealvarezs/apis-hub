<?php

    namespace Tests\Unit\Repositories;

    use Doctrine\ORM\AbstractQuery;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\Query;
    use Doctrine\ORM\QueryBuilder;
    use Anibalealvarezs\ApiSkeleton\Enums\Period;
    use Entities\Analytics\Channel;

    // Added this line
    use Enums\QueryBuilderType;
    use Helpers\Helpers;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionException;
    use ReflectionMethod;
    use Repositories\MetricRepository;

    class MetricRepositoryTest extends TestCase
    {
        private MockObject|QueryBuilder $queryBuilder;
        private MockObject|Query $query;
        private MetricRepository $repository;
        private string $entityName = 'Entities\Analytics\Metric';

        // Added this line

        protected function setUp(): void
        {
            $entityManager = $this->createMock(EntityManager::class);
            $this->queryBuilder = $this->createMock(QueryBuilder::class);
            $this->query = $this->createMock(Query::class);

            $entityManager->method('createQueryBuilder')
                ->willReturn($this->queryBuilder);

            $this->queryBuilder->method('select')->willReturnSelf();
            $this->queryBuilder->method('addSelect')->willReturnSelf();
            $this->queryBuilder->method('from')->willReturnSelf();
            $this->queryBuilder->method('leftJoin')->willReturnSelf();
            $this->queryBuilder->method('join')->willReturnSelf();
            $this->queryBuilder->method('where')->willReturnSelf();
            $this->queryBuilder->method('andWhere')->willReturnSelf();
            $this->queryBuilder->method('setParameter')->willReturnSelf();
            $this->queryBuilder->method('setParameters')->willReturnSelf();
            $this->queryBuilder->method('setMaxResults')->willReturnSelf();
            $this->queryBuilder->method('getQuery')->willReturn($this->query);

            $classMetadata = $this->createMock(ClassMetadata::class);
            $classMetadata->fieldMappings = [];
            $classMetadata->name = $this->entityName;
            $entityManager->method('getClassMetadata')
                ->with($this->entityName)
                ->willReturn($classMetadata);

            // Start of changes for Channel mocking
            $channel = $this->createMock(Channel::class);
            $channel->method('getName')->willReturn('shopify');
            $channel->method('getId')->willReturn(1); // Added this line

            $channelRepository = $this->createMock(EntityRepository::class);
            $channelRepository->method('find')->with(1)->willReturn($channel);
            $channelRepository->method('findOneBy')->willReturn($channel); // Keep this for other potential uses

            $entityManager->method('getRepository')
                ->willReturnCallback(function ($className) use ($channelRepository) {
                    if (str_contains($className, 'Channel') && !str_contains($className, 'Channeled')) {
                        return $channelRepository;
                    }

                    return $this->createMock(EntityRepository::class);
                });
            // End of changes for Channel mocking

            Helpers::setEntityManager($entityManager);

            $this->repository = new MetricRepository($entityManager, $classMetadata);
            $reflection = new ReflectionClass($this->repository);
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
                ->with('partial e.{id, value, metricDate, metadata}')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->exactly(15))
                ->method('addSelect')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->once())
                ->method('from')
                ->with($this->entityName, 'e')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->exactly(15))
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

        public function testExistsByChannelAndName(): void
        {
            $date = new \DateTime();
            $this->query->method('getSingleScalarResult')->willReturn(1);

            $result = $this->repository->existsByChannelAndName(1, 'test', Period::Daily, $date);
            $this->assertTrue($result);
        }

        /**
         * @throws ReflectionException
         */
        public function testReplaceChannelName(): void
        {
            $input = [
                'id'               => 1,
                'channel'          => 1,
                'channeledMetrics' => []
            ];

            $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
            $reflection->setAccessible(true);
            $result = $reflection->invoke($this->repository, $input);

            $this->assertEquals('shopify', $result['channel']);
        }

        /**
         * @throws ReflectionException
         */
        public function testStripPositionWeighted(): void
        {
            $input = [
                'channeledMetrics' => [
                    [
                        'data' => [
                            'position_weighted' => 123,
                            'other'             => 456
                        ]
                    ]
                ],
                'query'            => [
                    'data' => [
                        'position_weighted' => 123
                    ]
                ]
            ];

            $reflection = new ReflectionMethod($this->repository, 'stripPositionWeighted');
            $reflection->setAccessible(true);
            $result = $reflection->invoke($this->repository, $input);

            $this->assertArrayNotHasKey('position_weighted', $result['channeledMetrics'][0]['data']);
            $this->assertArrayHasKey('other', $result['channeledMetrics'][0]['data']);
            $this->assertArrayNotHasKey('position_weighted', $result['query']['data']);
        }
    }