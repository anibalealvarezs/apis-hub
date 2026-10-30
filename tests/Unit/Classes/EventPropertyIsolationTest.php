<?php

namespace Tests\Unit\Classes;

use Classes\MetricsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Tests\Unit\BaseUnitTestCase;

final class EventPropertyIsolationTest extends BaseUnitTestCase
{
    public function testDiscoveryResolvesTheAccountWithinItsChannel(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $connection = $this->createMock(Connection::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getRepository')->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => 5, 'name' => 'custom_quote']],
            [['id' => 22, 'platform_id' => '123', 'channel' => 3], ['id' => 99, 'platform_id' => '123', 'channel' => 4]]
        );
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(function ($sql, $params) {
            if (str_contains($sql, 'channeled_events')) {
                self::assertSame(['custom_quote', 5, 22, 3], array_slice($params, 0, 4));
            }
            return 1;
        });
        \Classes\EventProcessor::processEvents(new ArrayCollection([(object)[
            'name' => 'custom_quote', 'platformId' => 'custom_quote', 'channeledAccountId' => '123', 'channel' => 3,
        ]]), $manager);
    }

    public function testExistingEventInAnotherPropertyDoesNotSuppressInsertionOrStealIdentity(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $connection = $this->createMock(Connection::class);
        $manager->method('getConnection')->willReturn($connection);
        $result = $this->createMock(Result::class);
        $existing = ['id' => 101, 'platform_id' => 'custom_quote', 'channeled_account_id' => 11, 'event_id' => 5];
        $inserted = ['id' => 202, 'platform_id' => 'custom_quote', 'channeled_account_id' => 22, 'event_id' => 5];
        $result->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([$existing], [$existing, $inserted]);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects(self::once())->method('executeStatement')->with(self::anything(), self::callback(function ($params) {
            self::assertSame('custom_quote', $params[0]);
            self::assertSame(22, $params[2]);
            return true;
        }))->willReturn(1);
        $metric = (object)['channel' => 'google_analytics', 'channeledAccountPlatformId' => 'property-b', 'channeledEventPlatformId' => 'custom_quote'];
        $accounts = ['map' => ['property-b' => 22]];
        $map = MetricsProcessor::processChanneledEvents(new ArrayCollection([$metric]), $manager, $accounts, ['map' => ['custom_quote' => 5]]);
        self::assertSame(101, $map['map']['by_account'][11]['custom_quote']);
        self::assertSame(202, $map['map']['by_account'][22]['custom_quote']);
        $resolve = new \ReflectionMethod(MetricsProcessor::class, 'resolveChanneledEventId');
        self::assertSame(202, $resolve->invoke(null, $metric, $map, $accounts));
        self::assertNull($resolve->invoke(null, $metric, $map, ['map' => ['property-b' => 33]]));
    }
}
