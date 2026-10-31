<?php

namespace Tests\Unit\Classes;

use Classes\EventProcessor;
use Classes\MetricsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Entities\Analytics\Channel;
use Tests\Unit\BaseUnitTestCase;

final class EventCrossChannelRegressionTest extends BaseUnitTestCase
{
    /** @dataProvider channels */
    public function testMetricsWithoutEventsStillPersistWithNullEventLinks(string $channel): void
    {
        $manager = $this->createMock(EntityManager::class);
        $connection = $this->createMock(Connection::class);
        $manager->method('getConnection')->willReturn($connection);
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $connection->method('executeQuery')->willReturnCallback(function ($sql) use ($result) {
            self::assertStringNotContainsString('FROM events', $sql);
            self::assertStringNotContainsString('FROM channeled_events', $sql);
            return $result;
        });
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(function ($sql, $params) {
            self::assertStringContainsString('metric_configs', $sql);
            self::assertSame(22, $params[4]);
            self::assertNull($params[22]);
            self::assertNull($params[23]);
            return 1;
        });
        $metric = (object)['channel' => $channel, 'name' => 'clicks', 'period' => 'daily', 'channeledAccountPlatformId' => 'fixture-account'];
        MetricsProcessor::processMetricConfigs(
            new ArrayCollection([$metric]), $manager,
            processEvents: true, processChanneledEvents: true,
            channeledAccountMap: ['map' => ['fixture-account' => 22]],
            logger: new \Psr\Log\NullLogger()
        );
    }

    public static function channels(): array
    {
        return array_map(fn ($name) => [$name], ['facebook_marketing', 'facebook_organic', 'google_ads', 'google_search_console', 'mailchimp']);
    }

    public function testMailchimpEngagementSendAndUnsubscribeEntitiesRetainTheirAccountAndData(): void
    {
        $convert = \Anibalealvarezs\MailchimpHubDriver\Conversions\MailchimpConvert::class;
        $events = $convert::events([['email_id' => 'fixture-hash', 'activity' => [
            ['action' => 'open', 'timestamp' => '2026-10-01T12:00:00Z'],
            ['action' => 'click', 'timestamp' => '2026-10-01T12:01:00Z', 'url' => 'https://example.test/item'],
        ]]], 'campaign-fixture', 'list-fixture', 'account-fixture');
        foreach ($convert::sentToEvents([['email_id' => 'fixture-hash']], 'campaign-fixture', 'list-fixture', 'account-fixture', '2026-10-01T12:00:00Z') as $event) $events->add($event);
        foreach ($convert::unsubscribeEvents([['email_id' => 'fixture-hash']], 'campaign-fixture', 'list-fixture', 'account-fixture', '2026-10-01T12:02:00Z') as $event) $events->add($event);

        $manager = $this->createMock(EntityManager::class);
        $connection = $this->createMock(Connection::class);
        $manager->method('getConnection')->willReturn($connection);
        $repo = $this->createMock(EntityRepository::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(7);
        $repo->method('findOneBy')->with(['name' => 'mailchimp'])->willReturn($channel);
        $manager->method('getRepository')->willReturn($repo);
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => 1, 'name' => 'open'], ['id' => 2, 'name' => 'click'], ['id' => 3, 'name' => 'send'], ['id' => 4, 'name' => 'unsubscribe']],
            [['id' => 22, 'platform_id' => 'list-fixture', 'channel' => 7], ['id' => 99, 'platform_id' => 'list-fixture', 'channel' => 8]]
        );
        $connection->method('executeQuery')->willReturn($result);
        $expected = $events->toArray();
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(function ($sql, $params) use ($expected) {
            if (str_contains($sql, 'channeled_events')) {
                self::assertCount(28, $params);
                foreach (array_values($expected) as $index => $event) {
                    $row = array_slice($params, $index * 7, 7);
                    self::assertSame($event->platformId, $row[0]);
                    self::assertSame(22, $row[2]);
                    self::assertSame(7, $row[3]);
                    self::assertEquals((array)$event->data, json_decode($row[4], true));
                }
            }
            return 4;
        });
        EventProcessor::processEvents($events, $manager);
    }
}
