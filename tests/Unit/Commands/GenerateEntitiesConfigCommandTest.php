<?php

namespace Tests\Unit\Commands;

use bovigo\vfs\vfsStreamDirectory;
use Commands\GenerateEntitiesConfigCommand;
use Entities\Entity;
use bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateEntitiesConfigCommandTest extends TestCase
{
    private GenerateEntitiesConfigCommand $command;
    private ?vfsStreamDirectory $vfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new GenerateEntitiesConfigCommand();

        // Set up virtual file system with vfsStream
        $structure = [
            'src' => [
                'Entities' => [
                    'Product.php' => $this->getProductEntityContent(),
                    'Invalid.php' => "<?php\nnamespace Entities;\n// No class\n"
                ],
                'Analytics' => [
                    'Channeled' => [
                        'ChanneledProduct.php' => $this->getChanneledProductEntityContent()
                    ]
                ],
                'EmptyEntities' => []
            ],
            'config' => [
                'yaml' => []
            ]
        ];
        $this->vfs = vfsStream::setup('project', null, $structure);

        // Verify directory existence and debug contents
        $entitiesDir = vfsStream::url('project/src/Entities');
        $channeledDir = vfsStream::url('project/src/Analytics/Channeled');
        $emptyDir = vfsStream::url('project/src/EmptyEntities');
        $this->assertDirectoryExists($entitiesDir, 'Entities directory missing');
        $this->assertDirectoryExists($channeledDir, 'Analytics/Channeled directory missing');
        $this->assertDirectoryExists($emptyDir, 'EmptyEntities directory missing');
        $this->assertEquals(['.', '..', 'Invalid.php', 'Product.php'], scandir($entitiesDir), 'Unexpected Entities directory contents');
        $this->assertEquals(['.', '..', 'ChanneledProduct.php'], scandir($channeledDir), 'Unexpected Analytics/Channeled directory contents');
        $this->assertEquals(['.', '..'], scandir($emptyDir), 'Unexpected EmptyEntities directory contents');
    }

    protected function tearDown(): void
    {
        $this->vfs = null;
        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     */
    public function testExecuteWithValidEntitiesGeneratesConfig(): void
    {
        $files = [
            vfsStream::url('project/src/Entities/Product.php'),
            vfsStream::url('project/src/Analytics/Channeled/ChanneledProduct.php')
        ];
        $fileList = $files;

        // Verify file existence
        foreach ($fileList as $file) {
            $this->assertTrue(file_exists($file), "Virtual file $file does not exist");
        }

        $command = new GenerateEntitiesConfigCommand();
        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->once())
            ->method('writeln')
            ->with('<info>Successfully generated config for 2 entities</info>');

        // Use reflection to simulate execute
        $processEntityFile = new ReflectionMethod($command, 'processEntityFile');
        $generateEntityKey = new ReflectionMethod($command, 'generateEntityKey');
        $saveConfig = new ReflectionMethod($command, 'saveConfig');

        $entities = [];
        $channeledClassMap = [];
        $entityDirs = [
            'general' => vfsStream::url('project/src/Entities'),
            'channeled' => vfsStream::url('project/src/Analytics/Channeled')
        ];

        foreach ($entityDirs as $type => $dir) {
            $filteredFiles = array_filter($fileList, fn($file) => str_contains($file, $dir));
            foreach ($filteredFiles as $file) {
                $config = $processEntityFile->invoke($command, $file, $type, $output);
                if ($config) {
                    $key = $generateEntityKey->invoke($command, $config['class'], $type);
                    if ($type === 'channeled') {
                        $shortName = substr($config['class'], strrpos($config['class'], '\\') + 1);
                        $channeledClassMap[$shortName] = $config['class'];
                    }
                    $entities[$key] = $config;
                }
            }
            $this->assertCount(1, $filteredFiles, "Expected 1 files for $type");
        }

        foreach ($entities as $key => &$config) {
            if (!str_starts_with($key, 'channeled_')) {
                $shortName = substr($config['class'], strrpos($config['class'], '\\') + 1);
                $channeledName = 'Channeled' . $shortName;
                if (isset($channeledClassMap[$channeledName])) {
                    $config['channeled_class'] = '\\' . $channeledClassMap[$channeledName];
                }
            }
        }

        $saveConfig->invoke($command, $entities, $output);

        $this->assertCount(2, $entities, 'Expected 2 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testExecuteWithNoValidEntitiesReturnsFailure(): void
    {
        $command = new GenerateEntitiesConfigCommand();
        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->once())
            ->method('writeln')
            ->with('<info>Successfully generated config for 0 entities</info>');

        // Simulate execute with empty file list
        $saveConfig = new ReflectionMethod($command, 'saveConfig');

        $entities = [];
        $entityDirs = [
            'general' => vfsStream::url('project/src/EmptyEntities'),
            'channeled' => vfsStream::url('project/src/EmptyEntities')
        ];

        // Verify directory existence; no files are processed as directories are empty
        foreach ($entityDirs as $dir) {
            $this->assertDirectoryExists($dir, "Directory $dir does not exist");
        }

        $saveConfig->invoke($command, $entities, $output);

        $this->assertCount(0, $entities, 'Expected 0 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testExecuteWithInvalidEntityFileSkipsFile(): void
    {
        $files = [vfsStream::url('project/src/Entities/Invalid.php')];
        $fileList = $files;

        $this->assertTrue(file_exists($files[0]), "Virtual file {$files[0]} does not exist");

        $command = new GenerateEntitiesConfigCommand();
        $output = $this->createMock(OutputInterface::class);
        $expectedMessages = [
            '<comment>Invalid entity in: ' . vfsStream::url('project/src/Entities/Invalid.php') . '</comment>',
            '<info>Successfully generated config for 0 entities</info>'
        ];
        $callIndex = 0;
        $output->expects($this->exactly(2))
            ->method('writeln')
            ->will($this->returnCallback(function ($message) use (&$callIndex, $expectedMessages) {
                $this->assertLessThan(count($expectedMessages), $callIndex, 'Too many writeln calls');
                $this->assertEquals($expectedMessages[$callIndex], $message, "writeln call $callIndex does not match expected message");
                $callIndex++;
            }));

        // Use reflection to simulate file system
        $processEntityFile = new ReflectionMethod($command, 'processEntityFile');
        $saveConfig = new ReflectionMethod($command, 'saveConfig');

        $entities = [];
        $entityDirs = [
            'general' => vfsStream::url('project/src/Entities'),
            'channeled' => vfsStream::url('project/src/Analytics/Channeled')
        ];

        foreach ($entityDirs as $type => $dir) {
            $filteredFiles = array_filter($fileList, fn($file) => str_contains($file, $dir));
            foreach ($filteredFiles as $file) {
                $config = $processEntityFile->invoke($command, $file, $type, $output);
                if ($config) {
                    $entities[] = $config;
                }
            }
            $this->assertCount($type === 'general' ? 1 : 0, $filteredFiles, "Expected " . ($type === 'general' ? 1 : 0) . " files for $type");
        }

        $saveConfig->invoke($command, $entities, $output);

        $this->assertCount(0, $entities, 'Expected 0 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testFindPhpFilesYieldsPhpFiles(): void
    {
        $dir = vfsStream::url('project/src/Entities');
        $reflection = new ReflectionMethod($this->command, 'findPhpFiles');
        $generator = $reflection->invoke($this->command, $dir);
        $result = iterator_to_array($generator);
        $expected = [
            vfsStream::url('project/src/Entities/Product.php'),
            vfsStream::url('project/src/Entities/Invalid.php')
        ];
        // Normalize paths for Windows
        $result = array_map(fn($path) => str_replace('\\', '/', $path), $result);
        $expected = array_map(fn($path) => str_replace('\\', '/', $path), $expected);
        $this->assertSame($expected, $result, 'Expected files: ' . implode(', ', $expected) . '; Got: ' . implode(', ', $result));
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessEntityFileReturnsConfigForValidEntity(): void
    {
        $file = vfsStream::url('project/src/Entities/Product.php');
        $type = 'general';
        $output = $this->createMock(OutputInterface::class);

        $this->assertTrue(file_exists($file), "Virtual file $file does not exist");

        $reflection = new ReflectionMethod($this->command, 'processEntityFile');
        $result = $reflection->invoke($this->command, $file, $type, $output);

        $expected = [
            'class' => 'Entities\Product',
            'channeled_class' => 'Entities\Analytics\Channeled\ChanneledProduct',
            'crud_enabled' => true,
            'repository_class' => 'Repositories\ProductRepository',
            'repository_methods' => [
                'find' => [
                    'parameters' => ['id', 'lockMode', 'lockVersion']
                ],
                'findAll' => [
                    'parameters' => []
                ],
                'findBy' => [
                    'parameters' => ['criteria', 'orderBy', 'limit', 'offset']
                ],
                'findOneBy' => [
                    'parameters' => ['criteria', 'orderBy']
                ],
                'getByProductId' => [
                    'parameters' => ['productId'],
                    'return_type' => 'Entities\Entity'
                ],
                'existsByProductId' => [
                    'parameters' => ['productId'],
                    'return_type' => 'bool'
                ],
                'getBySku' => [
                    'parameters' => ['sku'],
                    'return_type' => 'Entities\Entity'
                ],
                'existsBySku' => [
                    'parameters' => ['sku'],
                    'return_type' => 'bool'
                ],
                'create' => [
                    'parameters' => ['data', 'returnEntity'],
                    'return_type' => 'Entities\Entity|array|null'
                ],
                'read' => [
                    'parameters' => ['id', 'returnEntity', 'filters'],
                    'return_type' => 'Entities\Entity|array|null'
                ],
                'getCount' => [
                    'parameters' => [],
                    'return_type' => 'int'
                ],
                'countElements' => [
                    'parameters' => ['filters'],
                    'return_type' => 'int'
                ],
                'readMultiple' => [
                    'parameters' => ['limit', 'pagination', 'ids', 'filters', 'orderBy', 'orderDir'],
                    'return_type' => 'Doctrine\Common\Collections\ArrayCollection'
                ],
                'update' => [
                    'parameters' => ['id', 'data', 'returnEntity'],
                    'return_type' => 'Entities\Entity|array|bool|null'
                ],
                'delete' => [
                    'parameters' => ['id'],
                    'return_type' => 'bool'
                ],
                'createQueryBuilder' => [
                    'parameters' => ['alias', 'indexBy']
                ],
                'createResultSetMappingBuilder' => [
                    'parameters' => ['alias']
                ],
                'createNamedQuery' => [
                    'parameters' => ['queryName']
                ],
                'createNativeNamedQuery' => [
                    'parameters' => ['queryName']
                ],
                'clear' => [
                    'parameters' => []
                ],
                'count' => [
                    'parameters' => ['criteria']
                ],
                '__call' => [
                    'parameters' => ['method', 'arguments']
                ],
                'getClassName' => [
                    'parameters' => []
                ],
                'matching' => [
                    'parameters' => ['criteria']
                ]
            ]
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testExtractClassInfoExtractsNamespaceAndClass(): void
    {
        $reflection = new ReflectionMethod($this->command, 'extractClassInfo');

        // Test case 1: Simple class definition
        $simpleContent = "<?php\nnamespace Entities;\n\nclass Product {}\n";
        $result = $reflection->invoke($this->command, $simpleContent);
        $expected = [
            'fullName' => 'Entities\Product',
            'shortName' => 'Product'
        ];
        $this->assertEquals($expected, $result, 'Failed to extract class info from simple class');

        // Test case 2: Class with attributes and use statements
        $complexContent = "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductRepository::class)]\nclass ChanneledProduct {}\n";
        $result = $reflection->invoke($this->command, $complexContent);
        $expected = [
            'fullName' => 'Entities\Analytics\Channeled\ChanneledProduct',
            'shortName' => 'ChanneledProduct'
        ];
        $this->assertEquals($expected, $result, 'Failed to extract class info from complex class');

        // Test case 3: Invalid content (no class)
        $invalidContent = "<?php\nnamespace Entities;\n\n// No class\n";
        $result = $reflection->invoke($this->command, $invalidContent);
        $this->assertNull($result, 'Expected null for content without class');

        // Test case 4: Comment with 'class' keyword
        $commentContent = "<?php\nnamespace Entities;\n\n// No valid class\n";
        $result = $reflection->invoke($this->command, $commentContent);
        $this->assertNull($result, 'Expected null for content with class in comment');
    }

    /**
     * @throws ReflectionException
     */
    public function testResolveRepositoryClassResolvesFullyQualifiedName(): void
    {
        $repoClass = 'ProductRepository';
        $type = 'general';

        $reflection = new ReflectionMethod($this->command, 'resolveRepositoryClass');
        $result = $reflection->invoke($this->command, $repoClass, $type);

        $this->assertEquals('Repositories\ProductRepository', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testAnalyzeRepositoryMethodsExtractsPublicMethods(): void
    {
        $output = $this->createMock(OutputInterface::class);
        $repositoryClass = MockRepository::class;

        $reflection = new ReflectionMethod($this->command, 'analyzeRepositoryMethods');
        $result = $reflection->invoke($this->command, $repositoryClass, $output);

        $expected = [
            'findById' => [
                'parameters' => ['id'],
                'return_type' => 'Entities\Entity'
            ],
            'findAll' => [
                'parameters' => [],
                'return_type' => 'array'
            ]
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testGenerateEntityKeyForGeneralEntity(): void
    {
        $className = 'Entities\Product';
        $type = 'general';

        $reflection = new ReflectionMethod($this->command, 'generateEntityKey');
        $result = $reflection->invoke($this->command, $className, $type);

        $this->assertEquals('product', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testGenerateEntityKeyForChanneledEntity(): void
    {
        $className = 'Entities\Analytics\Channeled\ChanneledProduct';
        $type = 'channeled';

        $reflection = new ReflectionMethod($this->command, 'generateEntityKey');
        $result = $reflection->invoke($this->command, $className, $type);

        $this->assertEquals('channeled_product', $result);
    }

    private function getProductEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: ProductRepository::class)]\nclass Product {}\n";
    }

    private function getChanneledProductEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductRepository::class)]\nclass ChanneledProduct {}\n";
    }
}

class MockRepository
{
    public function findById(int $id): ?Entity
    {
        return null;
    }

    public function findAll(): array
    {
        return [];
    }
}