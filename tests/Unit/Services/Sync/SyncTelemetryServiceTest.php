<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Helpers\Helpers;
use PDO;
use PHPUnit\Framework\TestCase;
use Services\CacheService;
use Services\Sync\SyncTelemetryService;

final class SyncTelemetryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Helpers::setEntityManager(null);
        parent::tearDown();
    }

    public function testWaitingHistoricalJobsStayVisibleUntilCompleted(): void
    {
        // Execute the service's aggregate SQL, using SQLite's compatible JSON
        // extraction plus an identity JSON_UNQUOTE for the MySQL query branch.
        $db = new PDO('sqlite::memory:');
        $db->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value);
        $db->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, channel TEXT, payload TEXT, status INTEGER)');
        $db->exec('CREATE TABLE channeled_accounts (platform_id TEXT, enabled INTEGER)');
        $insert = $db->prepare('INSERT INTO jobs VALUES (?, ?, ?, 1)');
        for ($id = 1; $id <= 9; $id++) {
            $insert->execute([$id, 'google_search_console', json_encode([
                'params' => ['account_id' => 'asset-'.(int)ceil($id / 3), 'type' => 'historical'],
            ])]);
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql']);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($db): array {
                $query = $db->prepare($sql);
                $query->execute($params);
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        );
        $em = $this->createMock(EntityManager::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getConnection')->willReturn($connection);
        $accounts = $this->createMock(EntityRepository::class);
        $accounts->method('findBy')->willReturn([]);
        $em->method('getRepository')->willReturn($accounts);
        Helpers::setEntityManager($em);

        $cache = $this->createMock(CacheService::class);
        $cache->method('get')->willReturnCallback(static fn ($key, $callback) => $callback());
        $service = new SyncTelemetryService($cache);

        $db->exec('UPDATE jobs SET status = 3 WHERE id = 1');
        for ($delayed = 0; $delayed <= 8; $delayed++) {
            if ($delayed > 0) {
                $db->exec('UPDATE jobs SET status = 5 WHERE id = '.($delayed + 1));
            }
            $channel = $service->getChannelStatus('google_search_console');
            $global = $service->getGlobalStatus()['channels']['google_search_console'];
            foreach ([$channel, $global] as $result) {
                self::assertSame(9, $result['total_jobs']);
                self::assertSame(1, $result['completed']);
                self::assertSame(8, $result['scheduled']);
                foreach ($result['assets'] as $asset) {
                    self::assertSame(3, $asset['total_for_percentage']);
                }
            }
        }

        $db->exec('UPDATE jobs SET status = 2 WHERE id = 2');
        $channel = $service->getChannelStatus('google_search_console');
        self::assertSame(7, $channel['scheduled']);
        self::assertSame(1, $channel['processing']);
        $db->exec('UPDATE jobs SET status = 3');
        self::assertSame(100.0, $service->getChannelStatus('google_search_console')['completion_percentage']);
    }
}
