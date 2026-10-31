<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\ChannelSqlFilter;

final class ChannelSqlFilterTest extends TestCase
{
    /** @dataProvider filters */
    public function testNamesIdsAndMixedMembershipRetainChannelIsolation(string $operator, mixed $value, array $expected): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
        $db->executeStatement("INSERT INTO channels VALUES (1,'google_analytics'),(2,'google_ads'),(3,'mailchimp')");
        $db->executeStatement('CREATE TABLE metric_configs (channel INTEGER)');
        $db->executeStatement('INSERT INTO metric_configs VALUES (1),(2),(3)');
        $predicate = (new ChannelSqlFilter)->compile(['operator'=>$operator,'value'=>$value], 'channel');
        self::assertSame($expected, array_map('intval', $db->fetchFirstColumn('SELECT channel FROM metric_configs mc WHERE '.$predicate['sql'].' ORDER BY channel', $predicate['params'])));
        $db->close();
    }

    public static function filters(): array
    {
        return [
            ['eq','google_analytics',[1]], ['eq','2',[2]], ['in',['google_analytics',2],[1,2]],
            ['not_in',['google_analytics',2],[3]], ['neq','google_analytics',[2,3]],
            ['eq',"google_analytics' OR 1=1 --",[]], ['in',[],[]], ['not_in',[],[1,2,3]],
        ];
    }
}
