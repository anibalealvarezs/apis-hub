<?php

    namespace Tests\Unit\Repositories\Channeled;

    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\NonUniqueResultException;
    use Doctrine\ORM\NoResultException;
    use Doctrine\ORM\Query;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Analytics\Channel;
    use Entities\Entity;
    use Enums\QueryBuilderType;
    use Faker\Factory;
    use Faker\Generator;
    use Helpers\Helpers;
    use MockChannelRepository;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use ReflectionException;
    use ReflectionMethod;
    use Repositories\Channeled\ChanneledVendorRepository;

    class ChanneledVendorRepositoryTest extends TestCase
    {
        protected Generator $faker;
        private MockObject|QueryBuilder $queryBuilder;
        private MockObject|Query $query;
        private ChanneledVendorRepository $repository;
        private string $entityName = 'Entities\Entity';

        protected function setUp(): void
        {
            parent::setUp();
            $entityManager = $this->createMock(EntityManager::class);
            $this->queryBuilder = $this->createMock(QueryBuilder::class);
            $this->query = $this->createMock(Query::class);
            $this->entityName = 'Entities\Entity';
            $classMetadata = $this->createMock(ClassMetadata::class);
            $classMetadata->name = $this->entityName;
            $entityManager->expects($this->any())
                ->method('getClassMetadata')
                ->with($this->entityName)
                ->willReturn($classMetadata);
            $entityManager->expects($this->any())
                ->method('createQueryBuilder')
                ->willReturn($this->queryBuilder);
            $this->queryBuilder->method('setMaxResults')->willReturnSelf();

            $entityManager->method('getRepository')
                ->willReturnCallback(function ($className) {
                    if (str_contains($className, 'Channel') && !str_contains($className, 'Channeled')) {
                        return new MockChannelRepository();
                    }

                    return $this->createMock(EntityRepository::class);
                });
            Helpers::setEntityManager($entityManager);

            $this->repository = new ChanneledVendorRepository($entityManager, $classMetadata);
            $this->faker = Factory::create();
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
                ->with('p')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->once())
                ->method('from')
                ->with($this->entityName, 'e')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->once())
                ->method('leftJoin')
                ->with('e.channeledProducts', 'p')
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
         * @throws ReflectionException
         */
        public function testCreateBaseQueryBuilderLast(): void
        {
            $this->queryBuilder->expects($this->once())
                ->method('select')
                ->with('e, LENGTH(e.platformId) AS HIDDEN length')
                ->willReturnSelf();

            $reflection = new ReflectionMethod($this->repository, 'createBaseQueryBuilder');
            $result = $reflection->invoke($this->repository, QueryBuilderType::LAST);

            $this->assertInstanceOf(QueryBuilder::class, $result);
        }

        /**
         * @throws ReflectionException
         */
        public function testReplaceChannelName(): void
        {
            $input = [
                'id'                => 1,
                'channel'           => 1,
                'channeledProducts' => [
                    ['id' => 2, 'channel' => 1],
                    ['id' => 3, 'channel' => 1]
                ]
            ];

            $channel = $this->createMock(Channel::class);
            $channel->method('getName')->willReturn('shopify');

            $this->repository->getEntityManager()->method('find')->willReturn($channel);

            $expected = [
                'id'                => 1,
                'channel'           => 'shopify',
                'channeledProducts' => [
                    ['id' => 2],
                    ['id' => 3]
                ]
            ];

            $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
            $result = $reflection->invoke($this->repository, $input);

            $this->assertEquals($expected, $result);
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetByNameReturnsEntity(): void
        {
            $name = $this->faker->company;
            $channel = 1;
            $entity = new Entity();

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn($entity);

            $result = $this->repository->getByName($name, $channel);

            $this->assertSame($entity, $result);
            $this->assertEquals(
                ['name' => $name, 'channel' => 1],
                $parameterCalls
            );
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetByNameReturnsNull(): void
        {
            $name = $this->faker->company;
            $channel = 1;

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn(null);

            $result = $this->repository->getByName($name, $channel);

            $this->assertNull($result);
            $this->assertEquals(
                ['name' => $name, 'channel' => 1],
                $parameterCalls
            );
        }

        public function testGetByNameWithInvalidChannel(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->repository->getByName($this->faker->company, 'invalid_channel');
        }

        /**
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function testExistsByNameReturnsTrue(): void
        {
            $name = $this->faker->company;
            $channel = 1;

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getSingleScalarResult')->willReturn(1);

            $result = $this->repository->existsByName($name, $channel);

            $this->assertTrue($result);
            $this->assertEquals(
                ['name' => $name, 'channel' => 1],
                $parameterCalls
            );
        }

        /**
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function testExistsByNameReturnsFalse(): void
        {
            $name = $this->faker->company;
            $channel = 1;

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getSingleScalarResult')->willReturn(0);

            $result = $this->repository->existsByName($name, $channel);

            $this->assertFalse($result);
            $this->assertEquals(
                ['name' => $name, 'channel' => 1],
                $parameterCalls
            );
        }

        public function testExistsByNameWithInvalidChannel(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->repository->existsByName($this->faker->company, 'invalid_channel');
        }
    }