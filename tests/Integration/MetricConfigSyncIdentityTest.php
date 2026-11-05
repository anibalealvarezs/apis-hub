<?php

declare(strict_types=1);

namespace Tests\Integration;

use Classes\MetricsProcessor;
use Commands\RecalculateMetricConfigSignaturesCommand;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Services\Analytics\MetricConfigSignature;
use Symfony\Component\Console\Tester\CommandTester;

final class MetricConfigSyncIdentityTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private mixed $previousDbConfig;
    private mixed $previousManager;

    protected function setUp(): void
    {
        $this->previousDbConfig = (new \ReflectionProperty(Helpers::class, 'dbConfig'))->getValue();
        $this->previousManager = (new \ReflectionProperty(Helpers::class, 'entityManager'))->getValue();
        (new \ReflectionProperty(Helpers::class, 'dbConfig'))->setValue(null, ['driver' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->manager = $this->createMock(EntityManager::class);
        $this->manager->method('isOpen')->willReturn(true);
        $this->manager->method('getConnection')->willReturn($this->connection);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $this->manager->method('getRepository')->willReturn($repository);
        Helpers::setEntityManager($this->manager);

        // Build the persisted identity fixture from the shared canonical SELECT.
        $sql = MetricConfigSignature::selectSql();
        $tables = ['mc' => 'metric_configs'];
        preg_match_all('/LEFT JOIN (\w+) (\w+)/', $sql, $joins, PREG_SET_ORDER);
        foreach ($joins as $join) {
            $tables[$join[2]] = $join[1];
        }
        preg_match_all('/\b(\w+)\.(\w+)/', $sql, $references, PREG_SET_ORDER);
        $columns = [];
        foreach ($references as $reference) {
            $columns[$reference[1]][$reference[2]] = true;
        }
        foreach ($tables as $alias => $table) {
            $definitions = ['id INTEGER PRIMARY KEY'];
            foreach (array_keys($columns[$alias]) as $column) {
                if ($column === 'id') {
                    continue;
                }
                $type = $alias === 'mc' && ($column === 'channel' || str_ends_with($column, '_id')) ? 'INTEGER' : 'TEXT';
                $definitions[] = $column.' '.$type.($column === 'config_signature' ? ' UNIQUE' : '');
            }
            $this->connection->executeStatement('CREATE TABLE '.$table.' ('.implode(', ', $definitions).')');
        }
        $this->connection->executeStatement('CREATE TABLE metrics (
            id INTEGER PRIMARY KEY, metric_config_id INTEGER, metric_date TEXT,
            dimensions_hash TEXT, value REAL, metadata TEXT,
            UNIQUE(metric_config_id, metric_date, dimensions_hash))');
        $this->connection->insert('accounts', ['id' => 7, 'name' => 'property owner']);
        $this->connection->insert('channeled_accounts', ['id' => 46, 'platform_id' => 'property-a']);
        $this->connection->insert('channeled_accounts', ['id' => 47, 'platform_id' => 'property-b']);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Helpers::class, 'dbConfig'))->setValue(null, $this->previousDbConfig);
        (new \ReflectionProperty(Helpers::class, 'entityManager'))->setValue(null, $this->previousManager);
        $this->connection->close();
    }

    private function metric(string $campaign, string $property = 'property-a'): object
    {
        return (object) [
            'channel' => '1', 'name' => 'averageSessionDuration', 'period' => 'daily',
            'channeledAccountPlatformId' => $property,
            'channeledCampaignPlatformId' => $campaign,
            'metricDate' => '2026-11-03', 'value' => 125,
            'dimensionsHash' => 'fixture-dimensions', 'metadata' => null,
        ];
    }

    private function sync(ArrayCollection $metrics): array
    {
        return MetricsProcessor::processMetricConfigs(
            metrics: $metrics,
            manager: $this->manager,
            pageMap: ['map' => []],
            channeledAccountMap: [
                'map' => ['property-a' => 46, 'property-b' => 47],
                'global' => ['property-a' => 7, 'property-b' => 7],
            ],
            logger: new NullLogger()
        );
    }

    public function testRepeatedSyncUsesPersistedIdentityAndKeepsAssetsSeparate(): void
    {
        $first = $this->metric('unresolved-a');
        $second = $this->metric('unresolved-b');
        $map = $this->sync(new ArrayCollection([$first, $second]));
        self::assertCount(1, $map['map']);
        self::assertSame($first->metricConfigKey, $second->metricConfigKey);
        $this->sync(new ArrayCollection([$this->metric('unresolved-c')]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        $row = $this->connection->fetchAssociative(MetricConfigSignature::selectSql());
        self::assertSame(MetricConfigSignature::fromRow($row), $row['config_signature']);
        $this->sync(new ArrayCollection([$this->metric('unresolved-a', 'property-b')]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
    }

    public function testSyncReusesOldestLegacyConfiguration(): void
    {
        $this->sync(new ArrayCollection([$this->metric('old-label')]));
        $row = $this->connection->fetchAssociative('SELECT * FROM metric_configs');
        $id = (int) $row['id'];
        $this->connection->update('metric_configs', ['config_signature' => 'legacy-one'], ['id' => $id]);
        unset($row['id']);
        $row['config_signature'] = 'legacy-two';
        $this->connection->insert('metric_configs', $row);
        $map = $this->sync(new ArrayCollection([$this->metric('new-label')]));
        self::assertSame([$id], array_values($map['map']));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        self::assertSame('legacy-one', $this->connection->fetchOne('SELECT config_signature FROM metric_configs WHERE id = ?', [$id]));
    }

    public function testRepeatedPersistenceUpdatesExistingObservationsAfterLegacyReuse(): void
    {
        $this->connection->getNativeConnection()->sqliteCreateFunction('GREATEST', static fn ($a, $b) => max($a, $b));
        $seedDuration = $this->metric('seed-label');
        $seedSessions = $this->metric('seed-label');
        $seedSessions->name = 'sessions';
        $seedSessions->value = 1;
        $seed = new ArrayCollection([$seedDuration, $seedSessions]);
        $seedConfigs = $this->sync($seed);
        foreach ($seed as $metric) {
            $this->connection->insert('metrics', [
                'metric_config_id' => $seedConfigs['map'][$metric->metricConfigKey],
                'metric_date' => $metric->metricDate,
                'dimensions_hash' => $metric->dimensionsHash, 'value' => $metric->value,
            ]);
        }
        $this->connection->executeStatement("UPDATE metric_configs SET config_signature = 'legacy-' || id");
        foreach (['first-label', 'second-label'] as $label) {
            $duration = $this->metric($label);
            $sessions = $this->metric($label);
            $sessions->name = 'sessions';
            $sessions->value = 1;
            $collection = new ArrayCollection([$duration, $sessions]);
            $configs = $this->sync($collection);
            $observations = MetricsProcessor::processMetrics(
                $collection, $this->manager, $configs, new NullLogger()
            );
            self::assertCount(2, $observations['map']);
            // Simulate configurations written before canonical signature fixes.
            $this->connection->executeStatement("UPDATE metric_configs SET config_signature = 'legacy-' || id");
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metrics'));
    }

    public function testLifetimeDeltaReadsLegacyConfigByResolvedIdentity(): void
    {
        $first = $this->metric('old-label');
        $first->period = 'lifetime';
        $first->metricDate = '2026-11-02';
        $first->value = 100;
        $map = $this->sync(new ArrayCollection([$first]));
        $id = $map['map'][$first->metricConfigKey];
        $this->connection->update('metric_configs', ['config_signature' => 'legacy-lifetime'], ['id' => $id]);
        $this->connection->insert('metrics', [
            'metric_config_id' => $id, 'metric_date' => '2026-11-02',
            'dimensions_hash' => '', 'value' => 100,
        ]);
        $next = $this->metric('new-label');
        $next->period = 'lifetime';
        $collection = new ArrayCollection([$next]);
        $this->sync($collection);
        self::assertCount(2, $collection);
        self::assertSame(25.0, $collection->get(1)->value);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
    }

    public function testReconciliationPreviewHonorsBoundsAcrossBatches(): void
    {
        foreach ([10, 20, 30, 40] as $id) {
            $this->connection->insert('metric_configs', [
                'id' => $id, 'channel' => 1, 'name' => 'metric-'.$id,
                'period' => 'daily', 'config_signature' => 'legacy-'.$id,
            ]);
        }
        $tester = new CommandTester(new RecalculateMetricConfigSignaturesCommand($this->manager));
        self::assertSame(0, $tester->execute(['--from-id' => 20, '--to-id' => 30, '--batch-size' => 1, '--dry-run' => true]));
        self::assertStringContainsString('2 configs would be updated', $tester->getDisplay());
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM metric_configs WHERE config_signature LIKE 'legacy-%'"));
    }

    public function testReconciliationDoesNotSkipRowsAfterDeletingDuplicates(): void
    {
        foreach ([10, 20, 30, 40] as $id) {
            $this->connection->insert('metric_configs', [
                'id' => $id, 'channel' => 1,
                'name' => $id === 30 ? 'metric-20' : 'metric-'.$id,
                'period' => 'daily', 'config_signature' => 'legacy-'.$id,
            ]);
        }
        $tester = new CommandTester(new RecalculateMetricConfigSignaturesCommand($this->manager));
        self::assertSame(0, $tester->execute(['--from-id' => 20, '--to-id' => 40, '--batch-size' => 1]));
        self::assertSame([10, 20, 40], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM metric_configs ORDER BY id')));
        self::assertSame('legacy-10', $this->connection->fetchOne('SELECT config_signature FROM metric_configs WHERE id = 10'));
        self::assertNotSame('legacy-40', $this->connection->fetchOne('SELECT config_signature FROM metric_configs WHERE id = 40'));
    }
}

