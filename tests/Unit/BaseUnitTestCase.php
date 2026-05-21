<?php

namespace Tests\Unit;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Entities\Analytics\Channel;
use Faker\Factory;
use Faker\Generator;
use Helpers\Helpers;
use MockChannelRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class BaseUnitTestCase extends TestCase
{
    protected Generator $faker;
    protected MockObject|EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = Factory::create();
        $this->entityManager = $this->createMock(EntityManager::class);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($className) {
                if (str_contains($className, 'Channel')) {
                    return new MockChannelRepository();
                }
                return $this->createMock(EntityRepository::class);
            });

        Helpers::setEntityManager($this->entityManager);
    }

    protected function getChannelEntity(string $name): ?Channel
    {
        $channelRepository = $this->entityManager->getRepository(Channel::class);
        return $channelRepository->findOneBy(['name' => $name]);
    }
}