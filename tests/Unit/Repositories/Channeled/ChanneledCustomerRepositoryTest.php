<?php

namespace Tests\Unit\Repositories\Channeled;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Entities\Entity;
use Enums\Channels;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Repositories\Channeled\ChanneledCustomerRepository;

class ChanneledCustomerRepositoryTest extends TestCase
{
    private Generator $faker;
    private MockObject|QueryBuilder $queryBuilder;
    private MockObject|AbstractQuery $query;
    private ChanneledCustomerRepository $repository;
    private string $entityName = 'Entities\Entity';

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = $this->createMock(EntityManager::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);
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
        $this->repository = new ChanneledCustomerRepository($entityManager, $classMetadata);
        $this->faker = Factory::create();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmailWithEnumReturnsEntity(): void
    {
        $email = $this->faker->email;
        $channel = Channels::shopify; // Enum
        $entity = new Entity();

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByEmail($email, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals(['channelId', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmailWithEnumReturnsNull(): void
    {
        $email = $this->faker->email;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn(null);

        $result = $this->repository->getByEmail($email, $channel);

        $this->assertNull($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals(['channelId', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmailWithIntegerReturnsEntity(): void
    {
        $email = $this->faker->email;
        $channel = 1;
        $entity = new Entity();

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->with('e.email = :email')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getOneOrNullResult')
            ->with(AbstractQuery::HYDRATE_OBJECT)
            ->willReturn($entity);

        $result = $this->repository->getByEmail($email, $channel);

        $this->assertSame($entity, $result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals('channelId', $parameterCalls[1][0]); // Check key only
    }

    /**
     * @throws NonUniqueResultException
     */
    public function testGetByEmailWithInvalidChannel(): void
    {
        $email = $this->faker->email;
        $channel = 999;

        $this->queryBuilder->expects($this->never())
            ->method('select');
        $this->queryBuilder->expects($this->never())
            ->method('from');
        $this->queryBuilder->expects($this->never())
            ->method('where');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->getByEmail($email, $channel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByEmailWithEnumReturnsTrue(): void
    {
        $email = $this->faker->email;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByEmail($email, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals(['channelId', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByEmailWithEnumReturnsFalse(): void
    {
        $email = $this->faker->email;
        $channel = Channels::shopify;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(0);

        $result = $this->repository->existsByEmail($email, $channel);

        $this->assertFalse($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals(['channelId', $channel->value], $parameterCalls[1]);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByEmailWithIntegerReturnsTrue(): void
    {
        $email = $this->faker->email;
        $channel = 1;

        $parameterCalls = [];
        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) use (&$parameterCalls) {
                $parameterCalls[] = [$key, $value];
                return $this->queryBuilder;
            });

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
            ->method('andWhere')
            ->with('e.channel = :channelId')
            ->willReturnSelf();
        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);
        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        $result = $this->repository->existsByEmail($email, $channel);

        $this->assertTrue($result);
        $this->assertCount(2, $parameterCalls);
        $this->assertEquals(['email', $email], $parameterCalls[0]);
        $this->assertEquals('channelId', $parameterCalls[1][0]); // Check key only
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function testExistsByEmailWithInvalidChannel(): void
    {
        $email = $this->faker->email;
        $channel = 999;

        $this->queryBuilder->expects($this->never())
            ->method('select');
        $this->queryBuilder->expects($this->never())
            ->method('from');
        $this->queryBuilder->expects($this->never())
            ->method('where');
        $this->queryBuilder->expects($this->never())
            ->method('setParameter');
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid channel');

        $this->repository->existsByEmail($email, $channel);
    }
}