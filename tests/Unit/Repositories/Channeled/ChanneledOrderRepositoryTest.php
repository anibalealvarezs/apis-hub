<?php

    namespace Tests\Unit\Repositories\Channeled;

    use Doctrine\ORM\AbstractQuery;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\NonUniqueResultException;
    use Doctrine\ORM\NoResultException;
    use Doctrine\ORM\Query;
    use Doctrine\ORM\QueryBuilder;
    use Entities\Entity;
    use Enums\QueryBuilderType;
    use Faker\Factory;
    use Faker\Generator;
    use Helpers\Helpers;
    use InvalidArgumentException;
    use MockChannelRepository;
    use PHPUnit\Framework\MockObject\MockObject;
    use ReflectionException;
    use ReflectionMethod;
    use Repositories\Channeled\ChanneledOrderRepository;
    use Tests\Unit\BaseUnitTestCase;

    class ChanneledOrderRepositoryTest extends BaseUnitTestCase
    {
        protected Generator $faker;
        private MockObject|QueryBuilder $queryBuilder;
        private MockObject|Query $query;
        private ChanneledOrderRepository $repository;
        private string $entityName = 'Entities\Entity';

        protected function setUp(): void
        {
            parent::setUp();
            $this->queryBuilder = $this->createMock(QueryBuilder::class);
            $this->query = $this->createMock(Query::class);
            $this->entityName = 'Entities\Entity';
            $classMetadata = $this->createMock(ClassMetadata::class);
            $classMetadata->name = $this->entityName;
            $this->entityManager->expects($this->any())
                ->method('getClassMetadata')
                ->with($this->entityName)
                ->willReturn($classMetadata);
            $this->entityManager->expects($this->any())
                ->method('createQueryBuilder')
                ->willReturn($this->queryBuilder);
            $this->queryBuilder->method('setMaxResults')->willReturnSelf();

            $this->repository = new ChanneledOrderRepository($this->entityManager, $classMetadata);
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
            $this->queryBuilder->expects($this->exactly(3))
                ->method('addSelect')
                ->withConsecutive(['c'], ['p'], ['d'])
                ->willReturnSelf();
            $this->queryBuilder->expects($this->once())
                ->method('from')
                ->with($this->entityName, 'e')
                ->willReturnSelf();
            $this->queryBuilder->expects($this->exactly(3))
                ->method('leftJoin')
                ->withConsecutive(
                    ['e.channeledCustomer', 'c'],
                    ['e.channeledProducts', 'p'],
                    ['e.channeledDiscounts', 'd']
                )
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
                'id'                 => 1,
                'channel'            => 1,
                'channeledCustomer'  => ['id' => 2, 'channel' => 1],
                'channeledProducts'  => [
                    ['id' => 3, 'channel' => 1],
                    ['id' => 4, 'channel' => 1]
                ],
                'channeledDiscounts' => [
                    ['id' => 5, 'channel' => 1]
                ]
            ];

            $channel = $this->createMock(\Entities\Analytics\Channel::class);
            $channel->method('getName')->willReturn('shopify');

            $this->repository->getEntityManager()->method('find')->willReturn($channel);

            $expected = [
                'id'                 => 1,
                'channel'            => 'shopify',
                'channeledCustomer'  => ['id' => 2],
                'channeledProducts'  => [
                    ['id' => 3, 'channel' => 'shopify'],
                    ['id' => 4, 'channel' => 'shopify']
                ],
                'channeledDiscounts' => [
                    ['id' => 5, 'channel' => 'shopify']
                ]
            ];

            $reflection = new ReflectionMethod($this->repository, 'replaceChannelName');
            $result = $reflection->invoke($this->repository, $input);

            $this->assertEquals($expected, $result);
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetByOrderIdReturnsEntity(): void
        {
            $orderId = $this->faker->uuid;
            $channel = $this->getChannelEntity('shopify');
            $entity = new Entity();

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn($entity);

            $result = $this->repository->getByPlatformId($orderId, $channel->getId());

            $this->assertSame($entity, $result);
            $this->assertEquals(
                ['platformId' => $orderId, 'channel' => $channel->getId()],
                $parameterCalls
            );
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetByOrderIdReturnsNull(): void
        {
            $orderId = $this->faker->uuid;
            $channel = $this->getChannelEntity('shopify');

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn(null);

            $result = $this->repository->getByPlatformId($orderId, $channel->getId());

            $this->assertNull($result);
            $this->assertEquals(
                ['platformId' => $orderId, 'channel' => $channel->getId()],
                $parameterCalls
            );
        }

        public function testGetByOrderIdWithInvalidChannel(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->repository->getByPlatformId($this->faker->uuid, 999);
        }

        /**
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function testExistsByOrderIdReturnsTrue(): void
        {
            $orderId = $this->faker->uuid;
            $channel = $this->getChannelEntity('shopify');

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getSingleScalarResult')->willReturn(1);

            $result = $this->repository->existsByPlatformId($orderId, $channel->getId());

            $this->assertTrue($result);
            $this->assertEquals(
                ['platformId' => $orderId, 'channel' => $channel->getId()],
                $parameterCalls
            );
        }

        /**
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function testExistsByOrderIdReturnsFalse(): void
        {
            $orderId = $this->faker->uuid;
            $channel = $this->getChannelEntity('shopify');

            $parameterCalls = [];
            $this->queryBuilder->method('setParameter')
                ->willReturnCallback(function ($name, $value) use (&$parameterCalls) {
                    $parameterCalls[$name] = $value;

                    return $this->queryBuilder;
                });

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getSingleScalarResult')->willReturn(0);

            $result = $this->repository->existsByPlatformId($orderId, $channel->getId());

            $this->assertFalse($result);
            $this->assertEquals(
                ['platformId' => $orderId, 'channel' => $channel->getId()],
                $parameterCalls
            );
        }

        public function testExistsByOrderIdWithInvalidChannel(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->repository->existsByPlatformId($this->faker->uuid, 999);
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetLastByPlatformId(): void
        {
            $channel = $this->getChannelEntity('shopify');
            $entity = new Entity();

            $this->queryBuilder->method('orderBy')->with('length', 'DESC')->willReturnSelf();
            $this->queryBuilder->method('addOrderBy')->with('e.platformCreatedAt', 'DESC')->willReturnSelf();
            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn($entity);

            $result = $this->repository->getLastByPlatformId($channel->getId());

            $this->assertSame($entity, $result);
        }

        /**
         * @throws NonUniqueResultException
         */
        public function testGetLastByPlatformCreatedAt(): void
        {
            $channel = $this->getChannelEntity('shopify');
            $entity = new Entity();

            $this->queryBuilder->method('orderBy')->with('e.platformCreatedAt', 'DESC')->willReturnSelf();
            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getOneOrNullResult')->willReturn($entity);

            $result = $this->repository->getLastByPlatformCreatedAt($channel->getId());

            $this->assertSame($entity, $result);
        }

        /**
         * @throws NoResultException
         * @throws NonUniqueResultException
         */
        public function testCountElements(): void
        {
            $channel = $this->getChannelEntity('shopify');

            $this->queryBuilder->method('getQuery')->willReturn($this->query);
            $this->query->method('getSingleScalarResult')->willReturn(5);

            $result = $this->repository->countElements((object)['channel' => $channel->getId()]);

            $this->assertEquals(5, $result);
        }

        public function testCountElementsWithInvalidChannelName(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->repository->countElements((object)['channel' => 999]);
        }
    }