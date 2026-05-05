<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Traits\MetricPeriodConditionSqlResolver;
    use Tests\Unit\BaseUnitTestCase;

    final class MetricPeriodConditionSqlResolverTest extends BaseUnitTestCase
    {
        public function testResolveUsesRequestedPeriodWhenValid(): void
        {
            $resolver = new MetricPeriodConditionSqlResolver();

            $mysql = $resolver->resolve('weekly', false);
            $postgres = $resolver->resolve('weekly', true);

            $this->assertSame("mc.period = 'weekly'", $mysql);
            $this->assertSame("LOWER(mc.period) = 'weekly'", $postgres);
        }

        public function testResolveFallsBackToDefaultWhenRequestedPeriodIsInvalid(): void
        {
            $resolver = new MetricPeriodConditionSqlResolver();

            $empty = $resolver->resolve('', false, 'daily');
            $invalid = $resolver->resolve('week;drop', false, 'monthly');

            $this->assertSame("mc.period = 'daily'", $empty);
            $this->assertSame("mc.period = 'monthly'", $invalid);
        }

        public function testResolveNormalizesCase(): void
        {
            $resolver = new MetricPeriodConditionSqlResolver();

            $sql = $resolver->resolve(' MONTHLY ', true);

            $this->assertSame("LOWER(mc.period) = 'monthly'", $sql);
        }
    }

