<?php

namespace Tests\Unit\Services;

use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;
use Services\CacheKeyGenerator;

/**
 * @group Services
 */
class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;
    private Generator $faker;

    protected function setUp(): void
    {
        $this->generator = new CacheKeyGenerator();
        $this->faker = Factory::create();
    }

    public function testForEntityWithIntegerId()
    {
        $entityType = $this->faker->word;
        $id = $this->faker->randomNumber();
        $expected = "entity:{$entityType}:{$id}";

        $result = $this->generator->forEntity($entityType, $id);
        $this->assertEquals($expected, $result);
    }

    public function testForEntityWithStringId()
    {
        $entityType = $this->faker->word;
        $id = $this->faker->slug;
        $expected = "entity:{$entityType}:{$id}";

        $result = $this->generator->forEntity($entityType, $id);
        $this->assertEquals($expected, $result);
    }

    public function testForChanneledEntityWithIntegerId()
    {
        $channel = $this->faker->word;
        $entityType = $this->faker->word;
        $id = $this->faker->randomNumber();
        $expected = "entity:{$channel}:{$entityType}:{$id}";

        $result = $this->generator->forChanneledEntity($channel, $entityType, $id);
        $this->assertEquals($expected, $result);
    }

    public function testForChanneledEntityWithStringId()
    {
        $channel = $this->faker->word;
        $entityType = $this->faker->word;
        $id = $this->faker->slug;
        $expected = "entity:{$channel}:{$entityType}:{$id}";

        $result = $this->generator->forChanneledEntity($channel, $entityType, $id);
        $this->assertEquals($expected, $result);
    }

    public function testForEntityWithSpecialCharacters()
    {
        $entityType = $this->faker->word . '@' . $this->faker->randomNumber();
        $id = $this->faker->word . '#' . $this->faker->randomNumber();
        $expected = "entity:{$entityType}:{$id}";

        $result = $this->generator->forEntity($entityType, $id);
        $this->assertEquals($expected, $result);
    }

    public function testForChanneledEntityWithSpecialCharacters()
    {
        $channel = $this->faker->word . '-' . $this->faker->randomNumber();
        $entityType = $this->faker->word . '_' . $this->faker->randomNumber();
        $id = $this->faker->word . ':' . $this->faker->randomNumber();
        $expected = "entity:{$channel}:{$entityType}:{$id}";

        $result = $this->generator->forChanneledEntity($channel, $entityType, $id);
        $this->assertEquals($expected, $result);
    }
}