<?php

namespace Tests\Unit;

use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;

abstract class BaseUnitTestCase extends TestCase
{
    protected Generator $faker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = Factory::create();
    }
}
