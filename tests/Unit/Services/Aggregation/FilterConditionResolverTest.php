<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Services\Aggregation\FilterConditionResolver;
    use Tests\Unit\BaseUnitTestCase;

    final class FilterConditionResolverTest extends BaseUnitTestCase
    {
        public function testResolvesObjectNotEqualOperatorAliases(): void
        {
            $resolver = new FilterConditionResolver();

            $result = $resolver->resolve((object)[
                'operator' => 'not_equal',
                'value'    => 'abc',
            ]);

            $this->assertSame(['operator' => 'neq', 'value' => 'abc'], $result);
        }

        public function testResolvesObjectNullOperators(): void
        {
            $resolver = new FilterConditionResolver();

            $isNull = $resolver->resolve((object)['operator' => 'null']);
            $isNotNull = $resolver->resolve((object)['operator' => 'is_not_null']);

            $this->assertSame(['operator' => 'is_null', 'value' => null], $isNull);
            $this->assertSame(['operator' => 'is_not_null', 'value' => null], $isNotNull);
        }

        public function testResolvesStringShortcuts(): void
        {
            $resolver = new FilterConditionResolver();

            $nullShortcut = $resolver->resolve('N/A');
            $notNullShortcut = $resolver->resolve('NOT_NULL');
            $neqShortcut = $resolver->resolve('!= test');

            $this->assertSame(['operator' => 'is_null', 'value' => null], $nullShortcut);
            $this->assertSame(['operator' => 'is_not_null', 'value' => null], $notNullShortcut);
            $this->assertSame(['operator' => 'neq', 'value' => 'test'], $neqShortcut);
        }

        public function testFallsBackToEquals(): void
        {
            $resolver = new FilterConditionResolver();

            $fromObject = $resolver->resolve((object)['operator' => 'eq', 'value' => 123]);
            $fromScalar = $resolver->resolve(456);

            $this->assertSame(['operator' => 'eq', 'value' => 123], $fromObject);
            $this->assertSame(['operator' => 'eq', 'value' => 456], $fromScalar);
        }
    }

