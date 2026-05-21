<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Services\Aggregation\TemporalDatePartSqlResolver;
    use Tests\Unit\BaseUnitTestCase;

    final class TemporalDatePartSqlResolverTest extends BaseUnitTestCase
    {
        public function testResolvesPostgresTemporalAliases(): void
        {
            $resolver = new TemporalDatePartSqlResolver();

            $daily = $resolver->resolve('daily', 'm.metric_date', true);
            $weekly = $resolver->resolve('weekly', 'm.metric_date', true);
            $dayName = $resolver->resolve('dayname', 'm.metric_date', true);

            $this->assertSame("TO_CHAR(m.metric_date, 'YYYY-MM-DD')", $daily);
            $this->assertSame("TO_CHAR(m.metric_date, 'IYYY-\"W\"IW')", $weekly);
            $this->assertSame("TO_CHAR(m.metric_date, 'Day')", $dayName);
        }

        public function testResolvesMysqlTemporalAliases(): void
        {
            $resolver = new TemporalDatePartSqlResolver();

            $daily = $resolver->resolve('daily', 'e.created_at', false);
            $weekly = $resolver->resolve('weekly', 'e.created_at', false);
            $quarterly = $resolver->resolve('quarterly', 'e.created_at', false);

            $this->assertSame('DATE(e.created_at)', $daily);
            $this->assertSame("CONCAT(YEAR(e.created_at), '-W', LPAD(WEEK(e.created_at), 2, '0'))", $weekly);
            $this->assertSame("CONCAT(YEAR(e.created_at), '-Q', QUARTER(e.created_at))", $quarterly);
        }

        public function testReturnsNullForUnknownField(): void
        {
            $sql = (new TemporalDatePartSqlResolver())->resolve('unknown_field', 'e.date', true);

            $this->assertNull($sql);
        }
    }

