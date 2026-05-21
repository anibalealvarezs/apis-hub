<?php

    namespace Tests\Unit\Repositories;

    use Doctrine\ORM\AbstractQuery;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\NonUniqueResultException;
    use Doctrine\ORM\NoResultException;
    use Doctrine\ORM\Query;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Analytics\Channel;

    // Added this line
    use Enums\QueryBuilderType;
    use Faker\Factory;
    use Faker\Generator;
    use Helpers\Helpers;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionException;
    use ReflectionMethod;
    use Repositories\DiscountRepository;

    class DiscountRepositoryTest extends TestCase
    {
        protected Generator $faker;
        // Declared as a property
        private MockObject|QueryBuilder $queryBuilder;
        private MockObject|Query $query;
        private DiscountRepository $repository;
        private string $entityName = 'Entities\Entity';

        // Added this line

        protected function setUp(): void
        {
            $this->faker = Factory::create();

            $entityManager = $this->createMock(EntityManager::class); // Assigned to $this->entityManager
            $this->queryBuilder = $this->createMock(QueryBuilder::class);
            $this->query = $this->createMock(Query::class);

            $entityManager->method('createQueryBuilder')
                ->willReturn($this->queryBuilder);

            $this->queryBuilder->method('select')->willReturnSelf();
            $this->queryBuilder->method('addSelect')->willReturnSelf();
            $this->queryBuilder->method('from')->willReturnSelf();
            $this->queryBuilder->method('leftJoin')->willReturnSelf();
            $this->queryBuilder->method('where')->willReturnSelf();
            $this->queryBuilder->method('setParameter')->willReturnSelf();
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
            $channel->method('getId')->willReturn(1);

            $channelRepoMock = $this->createMock(EntityRepository::class);
            $channelRepoMock->method('find')->with(1)->willReturn($channel);
            $channelRepoMock->method('findOneBy')->willReturn($channel); // Keep this for other potential uses

            $entityManager->method('getRepository')
                ->willReturnCallback(function ($className) use ($channelRepoMock) {
                    if (str_contains($className, 'Channel') && !str_contains($className, 'Channeled')) {
                        return $channelRepoMock;
                    }

                    return $this->createMock(EntityRepository::class);
                });
            // End of changes for Channel mocking

            Helpers::setEntityManager($entityManager);

            $this->repository = new DiscountRepository($entityManager, $classMetadata); // Used $this->entityManager
            $reflection = new ReflectionClass($this->repository);
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
                ->with($this->callback(function ($arg) use (&$addSelectCallCount) {
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
                ->with($this->callback(function ($arg) use (&$addSelectCallCount) {
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
                'id'                 => $this->faker->randomNumber(),
                'channeledDiscounts' => [
                    [
                        'channel'            => $channelId,
                        'channeledPriceRule' => ['channel' => 'some_channel'],
                    ],
                ],
            ];
            $expected = [
                'id'                 => $data['id'],
                'channeledDiscounts' => [
                    [
                        'channel'            => $channelName,
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
                'id'                 => $this->faker->randomNumber(),
                'channeledDiscounts' => [],
            ];
            $expected = [
                'id'                 => $data['id'],
                'channeledDiscounts' => [],
            ];

            $reflection = new ReflectionMethod($this->repository, 'processResult');
            $result = $reflection->invoke($this->repository, $data);

            $this->assertEquals($expected, $result);
        }
    }