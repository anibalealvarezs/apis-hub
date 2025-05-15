<?php

namespace Tests\Unit\Helpers;

use DateTime;
use Faker\Factory;
use Faker\Generator;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use ReflectionClass;
use ReflectionException;
use Doctrine\ORM\EntityManager;
use Entities\Entity;

class HelpersTest extends TestCase
{
    private Generator $faker;

    protected function setUp(): void
    {
        $this->faker = Factory::create();

        // Reset static properties before each test
        $reflection = new ReflectionClass(Helpers::class);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $property->setValue(null, null);
        }
    }

    public function testGetNumbersArray(): void
    {
        $limit = $this->faker->numberBetween(1, 1000);
        $result = Helpers::getNumbersArray($limit);

        $this->assertCount($limit, $result);
        $this->assertEquals(1, $result[1]);
        $this->assertEquals($limit, $result[$limit]);

        // Test default value
        $defaultResult = Helpers::getNumbersArray();
        $this->assertCount(100, $defaultResult);
    }

    /**
     * @throws ReflectionException
     */
    public function testListPrivateProperties(): void
    {
        $testObject = new class {
            private $privateProp;
            protected $protectedProp;
            public $publicProp;
        };

        $result = Helpers::listPrivateProperties($testObject);
        $this->assertCount(1, $result);
        $this->assertEquals('privateProp', $result[0]->getName());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetRedisClient(): void
    {
        $config = [
            'redis' => [
                'scheme' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 2.5,
            ]
        ];

        $this->setPrivateStaticProperty('cacheConfig', $config);

        try {
            $client = Helpers::getRedisClient();
            $this->assertInstanceOf(ClientInterface::class, $client);

            // Always perform at least one assertion before potential skip
            $this->assertTrue(true, 'Redis client created successfully');

            // Additional connection test if possible
            if (method_exists($client, 'ping')) {
                $response = $client->ping();
                $this->assertTrue($response === true || $response === 'PONG', 'Redis ping successful');
            }
        } catch (\Exception $e) {
            $this->fail('Failed to connect to Redis: ' . $e->getMessage());
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testGetManager(): void
    {
        $config = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'path' => ':memory:',
        ];

        $this->setPrivateStaticProperty('dbConfig', $config);
        $this->assertInstanceOf(EntityManager::class, Helpers::getManager());
    }

    public function testToCamelcase(): void
    {
        $testCases = [
            ['test_string', 'testString'],
            ['test-string', 'testString'],
            ['Test String', 'testString'],
            ['test', 'test'],
            ['alreadyCamelCase', 'alreadyCamelCase'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $this->assertEquals($expected, Helpers::toCamelcase($input));
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testJsonSerialize(): void
    {
        $id = $this->faker->randomNumber();
        $name = $this->faker->word;
        $now = new DateTime();

        $entity = new class($id, $name, $now) extends Entity {
            protected int $id;
            private string $name;
            protected DateTime $createdAt;
            protected DateTime $updatedAt;
            private ?DateTime $deletedAt;

            public function __construct(int $id, string $name, DateTime $createdAt) {
                $this->id = $id;
                $this->name = $name;
                $this->createdAt = $createdAt;
                $this->updatedAt = clone $createdAt;
                $this->deletedAt = null;
            }

            public function getId(): int { return $this->id; }
            public function getName(): string { return $this->name; }
            public function getCreatedAt(): DateTime { return $this->createdAt; }
            public function getUpdatedAt(): ?DateTime { return $this->updatedAt; }
            public function getDeletedAt(): ?DateTime { return $this->deletedAt; }
        };

        $result = Helpers::jsonSerialize($entity);
        $this->assertEquals($id, $result['id']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals($now, $result['createdAt']);
        $this->assertEquals($now, $result['updatedAt']);
        $this->assertNull($result['deletedAt']);

        $filteredResult = Helpers::jsonSerialize($entity, ['id']);
        $this->assertEquals(['id' => $id], $filteredResult);
    }

    public function testDataToObject(): void
    {
        $testData = ['test' => $this->faker->word];
        $encodedData = base64_encode(json_encode($testData));

        $result = Helpers::dataToObject($encodedData);
        $this->assertEquals($testData['test'], $result->test);

        $emptyResult = Helpers::dataToObject();
        $this->assertIsObject($emptyResult);
        $this->assertEmpty((array)$emptyResult);
    }

    public function testBodyToObject(): void
    {
        $testData = ['test' => $this->faker->sentence];
        $jsonData = json_encode($testData);

        $result = Helpers::bodyToObject($jsonData);
        $this->assertEquals($testData['test'], $result->test);

        $emptyResult = Helpers::bodyToObject();
        $this->assertIsObject($emptyResult);
        $this->assertEmpty((array)$emptyResult);
    }

    public function testMultiDimensionalArrayUnique(): void
    {
        $uniqueItem = ['id' => $this->faker->randomNumber(), 'name' => $this->faker->word];
        $duplicateItem = ['id' => $this->faker->randomNumber(), 'name' => $this->faker->word];

        $array = [
            $uniqueItem,
            $duplicateItem,
            $duplicateItem // duplicate
        ];

        $result = Helpers::multiDimensionalArrayUnique($array);
        $this->assertCount(2, $result);
        $this->assertContains($uniqueItem, $result);
        $this->assertContains($duplicateItem, $result);
    }

    public function testGetDomain(): void
    {
        $domains = [
            ['https://www.example.com', 'example.com'],
            ['http://example.com', 'example.com'],
            ['https://sub.example.com/path', 'sub.example.com'],
            ['https://www.example.co.uk', 'example.co.uk'],
            ['https://www.example.io?query=param', 'example.io'],
        ];

        foreach ($domains as [$url, $expected]) {
            $this->assertEquals($expected, Helpers::getDomain($url));
        }

        $this->assertNull(Helpers::getDomain('invalid-url'));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetEnabledCrudEntities(): void
    {
        $enabledEntities = [
            ['name' => $this->faker->word, 'crud_enabled' => true],
            ['name' => $this->faker->word, 'crud_enabled' => false],
            ['name' => $this->faker->word, 'crud_enabled' => true]
        ];

        $this->setPrivateStaticProperty('entitiesConfig', $enabledEntities);

        $result = Helpers::getEnabledCrudEntities();
        $this->assertCount(2, $result);

        $names = array_column($result, 'name');
        $this->assertContains($enabledEntities[0]['name'], $names);
        $this->assertContains($enabledEntities[2]['name'], $names);
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateStaticProperty(string $property, $value): void
    {
        $reflection = new ReflectionClass(Helpers::class);
        $property = $reflection->getProperty($property);
        $property->setValue(null, $value);
    }
}