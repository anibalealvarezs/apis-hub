<?php

namespace Tests\Integration;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Classes\Upgrades\Routines\Upgrade_v1_18_2_to_v1_18_3;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Entities\Analytics\Channeled\DimensionSet;
use PHPUnit\Framework\TestCase;
use Services\Analytics\MetricConfigSignature;
use Symfony\Component\Console\Output\BufferedOutput;

// Exercise real SQL and rollback in an isolated in-memory database. PostgreSQL's
// table lock is the only statement skipped; no configured tenant DB is accessed.
class GscUpgradeSqliteConnection extends Connection
{
    public array $updateStatements = [];
    public bool $failFinalWrite = false;
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with($sql, 'LOCK TABLE ') && $this->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) return 0;
        if (str_starts_with($sql, 'UPDATE ')) $this->updateStatements[] = $sql;
        if ($this->failFinalWrite && str_starts_with($sql, 'UPDATE metrics AS m')) throw new \RuntimeException('Injected final write failure');
        return parent::executeStatement($sql, $params, $types);
    }
}

class GscSearchTypeUpgradeTest extends TestCase
{
    protected Connection $conn;
    protected EntityManagerInterface $em;

    protected function connectFixture(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => GscUpgradeSqliteConnection::class]);
    }

    protected function fixtureSql(string $sql): string { return $sql; }

    protected function setUp(): void
    {
        $this->conn = $this->connectFixture();
        $sql = MetricConfigSignature::selectSql();
        $tables = ['mc' => 'metric_configs'];
        preg_match_all('/LEFT JOIN (\w+) (\w+)/', $sql, $joins, PREG_SET_ORDER);
        foreach ($joins as $join) $tables[$join[2]] = $join[1];
        preg_match_all('/\b(\w+)\.(\w+)/', $sql, $references, PREG_SET_ORDER);
        $columns = [];
        foreach ($references as $reference) $columns[$reference[1]][$reference[2]] = true;
        foreach ($tables as $alias => $table) {
            $definitions = ['id INTEGER PRIMARY KEY'];
            foreach (array_keys($columns[$alias] ?? []) as $column) {
                $type = $alias === 'mc' && ($column === 'channel' || str_ends_with($column, '_id')) ? 'INTEGER' : 'TEXT';
                if ($column !== 'id') $definitions[] = '"'.$column.'" '.$type.($column === 'hash' || $column === 'config_signature' ? ' UNIQUE' : '');
            }
            $this->conn->executeStatement($this->fixtureSql('CREATE TABLE '.$table.' ('.implode(', ', $definitions).')'));
        }
        foreach ([
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE dimension_keys (id INTEGER PRIMARY KEY, name TEXT UNIQUE)',
            'CREATE TABLE dimension_values (id INTEGER PRIMARY KEY, dimension_key_id INTEGER, value TEXT, UNIQUE(dimension_key_id, value))',
            'CREATE TABLE dimension_set_items (dimension_set_id INTEGER, dimension_value_id INTEGER, PRIMARY KEY(dimension_set_id, dimension_value_id))',
            'CREATE TABLE metrics (id INTEGER PRIMARY KEY, metric_config_id INTEGER, dimensions_hash TEXT, metric_date TEXT, value REAL, UNIQUE(metric_config_id, dimensions_hash, metric_date))',
            'CREATE TABLE channeled_metrics (id INTEGER PRIMARY KEY, metric_id INTEGER, dimension_set_id INTEGER, platform_id TEXT DEFAULT \'site\', channel INTEGER DEFAULT 1, platform_created_at TEXT DEFAULT \'2026-10-01\', UNIQUE(platform_id, channel, metric_id, platform_created_at))',
        ] as $statement) $this->conn->executeStatement($this->fixtureSql($statement));
        $this->conn->insert('channels', ['id' => 1, 'name' => 'google_search_console']);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->conn);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repository);
        $this->em->method('getReference')->willReturnCallback(static function ($class, $id) {
            $entity = new $class();
            (new \ReflectionProperty($class, 'id'))->setValue($entity, $id);
            return $entity;
        });
    }

    protected function seed(int $id, int $channel, ?string $type = null): void
    {
        $dimensions = [['dimensionKey' => 'page', 'dimensionValue' => 'https://example.com/']];
        if ($type !== null) $dimensions[] = ['dimensionKey' => 'search_type', 'dimensionValue' => $type];
        $set = (new \Classes\DimensionManager($this->em))->resolveDimensionSet($dimensions);
        $this->conn->insert('metric_configs', ['id' => $id, 'channel' => $channel, 'name' => 'clicks', 'period' => 'daily', 'dimension_set_id' => $set->getId(), 'config_signature' => 'old_'.$id]);
        $this->conn->insert('metrics', ['id' => $id, 'metric_config_id' => $id, 'dimensions_hash' => KeyGenerator::generateDimensionsHash($dimensions), 'metric_date' => '2026-10-01', 'value' => 7]);
        $this->conn->insert('channeled_metrics', ['id' => $id, 'metric_id' => $id, 'dimension_set_id' => $set->getId()]);
    }

    public function testUpgradeUpdatesAllIdentitiesAndPreservesTypedAndOtherChannels(): void
    {
        $this->seed(1, 1);
        $this->seed(2, 1, 'image');
        $this->seed(3, 2);
        $routine = new Upgrade_v1_18_2_to_v1_18_3();
        $routine->up($this->em, new BufferedOutput());
        $rows = $this->conn->fetchAllAssociative(MetricConfigSignature::selectSql());
        $this->assertSame(MetricConfigSignature::fromRow($rows[0]), $rows[0]['config_signature']);
        $this->assertSame($rows[0]['dimension_set_hash'], $this->conn->fetchOne('SELECT dimensions_hash FROM metrics WHERE id = 1'));
        $this->assertSame((int)$rows[0]['dimension_set_id'], (int)$this->conn->fetchOne('SELECT dimension_set_id FROM channeled_metrics WHERE id = 1'));
        $this->assertSame('old_2', $rows[1]['config_signature']);
        $this->assertSame('old_3', $rows[2]['config_signature']);
        $this->assertSame(7.0, (float)$this->conn->fetchOne('SELECT value FROM metrics WHERE id = 1'));
        $before = $this->conn->fetchAllAssociative('SELECT * FROM metric_configs ORDER BY id');
        $routine->up($this->em, new BufferedOutput());
        $this->assertSame($before, $this->conn->fetchAllAssociative('SELECT * FROM metric_configs ORDER BY id'));
    }

    public function testTypedCollisionMergesWithoutSummingValuesAndRetainsChannelLinks(): void
    {
        $this->seed(1, 1);
        $this->seed(2, 1, 'web');
        $rows = $this->conn->fetchAllAssociative(MetricConfigSignature::selectSql());
        $this->conn->executeStatement('UPDATE metric_configs SET config_signature = ? WHERE id = 2', [MetricConfigSignature::fromRow($rows[1])]);
        $this->conn->executeStatement('UPDATE metrics SET value = 9 WHERE id = 2');
        $this->conn->insert('channeled_metrics', ['id' => 3, 'metric_id' => 1, 'dimension_set_id' => 1, 'platform_id' => 'additional-source']);
        (new Upgrade_v1_18_2_to_v1_18_3())->up($this->em, new BufferedOutput());
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metrics'));
        $this->assertSame(9.0, (float)$this->conn->fetchOne('SELECT SUM(value) FROM metrics'));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT metric_config_id FROM metrics'));
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM channeled_metrics'));
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT metric_id FROM channeled_metrics WHERE id = 3'));
        $row = $this->conn->fetchAllAssociative(MetricConfigSignature::selectSql())[0];
        $this->assertSame(MetricConfigSignature::fromRow($row), $row['config_signature']);
    }

    public function testLargeBackfillStagesBatchesAndUpdatesEachMetricTableOnce(): void
    {
        $this->seed(1, 1);
        for ($id = 2; $id <= 501; $id++) {
            $this->conn->executeStatement('INSERT INTO metric_configs (id, channel, name, period, dimension_set_id, config_signature) SELECT ?, channel, ?, period, dimension_set_id, ? FROM metric_configs WHERE id = 1', [$id, 'metric_'.$id, 'old_'.$id]);
            $this->conn->executeStatement('INSERT INTO metrics SELECT ?, ?, dimensions_hash, metric_date, value FROM metrics WHERE id = 1', [$id, $id]);
            $this->conn->executeStatement('INSERT INTO channeled_metrics (id, metric_id, dimension_set_id) SELECT ?, ?, dimension_set_id FROM channeled_metrics WHERE id = 1', [$id, $id]);
        }
        $output = new BufferedOutput();
        (new Upgrade_v1_18_2_to_v1_18_3())->up($this->em, $output);
        $this->assertCount(3, $this->conn->updateStatements);
        $this->assertSame(501, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metric_configs WHERE config_signature NOT LIKE ?', ['old_%']));
        $this->assertSame(3507.0, (float)$this->conn->fetchOne('SELECT SUM(value) FROM metrics'));
        $this->assertStringContainsString('staged 501 configurations', $output->fetch());
    }

    public function testLegacyCollisionsKeepNonOverlappingDatesAndRollbackOnWriteFailure(): void
    {
        $this->seed(1, 1);
        $this->seed(2, 1);
        $this->conn->executeStatement('UPDATE metrics SET metric_date = ? WHERE id = 2', ['2026-10-02']);
        $this->conn->failFinalWrite = true;
        try {
            (new Upgrade_v1_18_2_to_v1_18_3())->up($this->em, new BufferedOutput());
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected final write failure', $error->getMessage());
        }
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        $this->assertSame('old_1', $this->conn->fetchOne('SELECT config_signature FROM metric_configs WHERE id = 1'));
        $this->conn->failFinalWrite = false;
        (new Upgrade_v1_18_2_to_v1_18_3())->up($this->em, new BufferedOutput());
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metrics'));
        $this->assertSame(14.0, (float)$this->conn->fetchOne('SELECT SUM(value) FROM metrics'));
    }

    public function testThreeLegacyDuplicatesKeepTheNewestObservationOnTheOldestConfig(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->seed($id, 1);
            $this->conn->executeStatement('UPDATE metrics SET value = ? WHERE id = ?', [$id, $id]);
        }
        (new Upgrade_v1_18_2_to_v1_18_3())->up($this->em, new BufferedOutput());
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM metric_configs'));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT metric_config_id FROM metrics'));
        $this->assertSame(3.0, (float)$this->conn->fetchOne('SELECT value FROM metrics'));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM channeled_metrics'));
    }
}
