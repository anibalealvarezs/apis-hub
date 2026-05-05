<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\DateSqlFieldResolver;
use Tests\Unit\BaseUnitTestCase;

final class DateSqlFieldResolverTest extends BaseUnitTestCase
{
    public function testResolvesMetricDateForChanneledMetric(): void
    {
        $sql = (new DateSqlFieldResolver())->resolveBaseDateSql(
            isChanneledMetric: true,
            isMetric: false,
            hasEntityField: static fn(string $field): bool => false,
        );

        $this->assertSame('m.metric_date', $sql);
    }

    public function testResolvesMetricDateForMetricEntity(): void
    {
        $sql = (new DateSqlFieldResolver())->resolveBaseDateSql(
            isChanneledMetric: false,
            isMetric: true,
            hasEntityField: static fn(string $field): bool => false,
        );

        $this->assertSame('e.metric_date', $sql);
    }

    public function testResolvesPlatformCreatedAtWhenAvailable(): void
    {
        $sql = (new DateSqlFieldResolver())->resolveBaseDateSql(
            isChanneledMetric: false,
            isMetric: false,
            hasEntityField: static fn(string $field): bool => $field === 'platformCreatedAt',
        );

        $this->assertSame('e.platform_created_at', $sql);
    }

    public function testFallsBackToCreatedAtOrDate(): void
    {
        $resolver = new DateSqlFieldResolver();

        $createdAtSql = $resolver->resolveBaseDateSql(
            isChanneledMetric: false,
            isMetric: false,
            hasEntityField: static fn(string $field): bool => $field === 'createdAt',
        );
        $dateSql = $resolver->resolveBaseDateSql(
            isChanneledMetric: false,
            isMetric: false,
            hasEntityField: static fn(string $field): bool => false,
        );

        $this->assertSame('e.created_at', $createdAtSql);
        $this->assertSame('e.date', $dateSql);
    }
}

