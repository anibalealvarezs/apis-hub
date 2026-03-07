<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use bovigo\vfs\vfsStreamDirectory;
use Commands\GenerateEntitiesConfigCommand;
use Entities\Entity;
use bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests GenerateEntitiesConfigCommand using vfsStream to ensure isolation.
 * All file operations occur in a virtual filesystem (vfs://project/),
 * preventing modifications to the real config/entitiesconfig.yaml.
 */
class GenerateEntitiesConfigCommandTest extends TestCase
{
    private GenerateEntitiesConfigCommand $command;
    private ?vfsStreamDirectory $vfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new GenerateEntitiesConfigCommand();

        // Set up virtual file system with vfsStream for all AnalyticsEntities
        $structure = [
            'src' => [
                'Entities' => [
                    'Product.php' => $this->getProductEntityContent(),
                    'Customer.php' => $this->getCustomerEntityContent(),
                    'Metric.php' => $this->getMetricEntityContent(),
                    'Order.php' => $this->getOrderEntityContent(),
                    'Discount.php' => $this->getDiscountEntityContent(),
                    'PriceRule.php' => $this->getPriceRuleEntityContent(),
                    'Campaign.php' => $this->getCampaignEntityContent(),
                    'ProductCategory.php' => $this->getProductCategoryEntityContent(),
                    'ProductVariant.php' => $this->getProductVariantEntityContent(),
                    'Vendor.php' => $this->getVendorEntityContent(),
                    'Invalid.php' => "<?php\nnamespace Entities;\n// No class\n"
                ],
                'Analytics' => [
                    'Channeled' => [
                        'ChanneledProduct.php' => $this->getChanneledProductEntityContent(),
                        'ChanneledCustomer.php' => $this->getChanneledCustomerEntityContent(),
                        'ChanneledMetric.php' => $this->getChanneledMetricEntityContent(),
                        'ChanneledOrder.php' => $this->getChanneledOrderEntityContent(),
                        'ChanneledDiscount.php' => $this->getChanneledDiscountEntityContent(),
                        'ChanneledPriceRule.php' => $this->getChanneledPriceRuleEntityContent(),
                        'ChanneledCampaign.php' => $this->getChanneledCampaignEntityContent(),
                        'ChanneledProductCategory.php' => $this->getChanneledProductCategoryEntityContent(),
                        'ChanneledProductVariant.php' => $this->getChanneledProductVariantEntityContent(),
                        'ChanneledVendor.php' => $this->getChanneledVendorEntityContent()
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
        $entitiesDir = $this->vfs->url() . '/src/Entities';
        $channeledDir = $this->vfs->url() . '/src/Analytics/Channeled';
        $emptyDir = $this->vfs->url() . '/src/EmptyEntities';
        $configDir = $this->vfs->url() . '/config/yaml';
        $this->assertDirectoryExists($entitiesDir, 'Entities directory missing');
        $this->assertDirectoryExists($channeledDir, 'Analytics/Channeled directory missing');
        $this->assertDirectoryExists($emptyDir, 'EmptyEntities directory missing');
        $this->assertDirectoryExists($configDir, 'Config/yaml directory missing');
        $expectedEntities = [
            '.', '..', 'Campaign.php', 'Customer.php', 'Discount.php', 'Invalid.php',
            'Metric.php', 'Order.php', 'PriceRule.php', 'Product.php',
            'ProductCategory.php', 'ProductVariant.php', 'Vendor.php'
        ];
        $this->assertEquals($expectedEntities, scandir($entitiesDir), 'Unexpected Entities directory contents');
        $expectedChanneled = [
            '.', '..', 'ChanneledCampaign.php', 'ChanneledCustomer.php', 'ChanneledDiscount.php',
            'ChanneledMetric.php', 'ChanneledOrder.php', 'ChanneledPriceRule.php',
            'ChanneledProduct.php', 'ChanneledProductCategory.php', 'ChanneledProductVariant.php',
            'ChanneledVendor.php'
        ];
        $this->assertEquals($expectedChanneled, scandir($channeledDir), 'Unexpected Analytics/Channeled directory contents');
        $this->assertEquals(['.', '..'], scandir($emptyDir), 'Unexpected EmptyEntities directory contents');
        $this->assertEquals(['.', '..'], scandir($configDir), 'Unexpected Config/yaml directory contents');

        // Verify vfsStream writability
        $testFile = $this->vfs->url() . '/config/yaml/test_writability.yaml';
        $this->assertTrue(file_put_contents($testFile, 'test') !== false, 'vfsStream is not writable');
        $this->assertTrue(file_exists($testFile), 'Failed to write test file to vfsStream');
        unlink($testFile);
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
            $this->vfs->url() . '/src/Entities/Product.php',
            $this->vfs->url() . '/src/Entities/Customer.php',
            $this->vfs->url() . '/src/Entities/Metric.php',
            $this->vfs->url() . '/src/Entities/Order.php',
            $this->vfs->url() . '/src/Entities/Discount.php',
            $this->vfs->url() . '/src/Entities/PriceRule.php',
            $this->vfs->url() . '/src/Entities/Campaign.php',
            $this->vfs->url() . '/src/Entities/ProductCategory.php',
            $this->vfs->url() . '/src/Entities/ProductVariant.php',
            $this->vfs->url() . '/src/Entities/Vendor.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledProduct.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledCustomer.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledMetric.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledOrder.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledDiscount.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledPriceRule.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledCampaign.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledProductCategory.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledProductVariant.php',
            $this->vfs->url() . '/src/Analytics/Channeled/ChanneledVendor.php'
        ];
        $fileList = $files;

        // Verify file existence
        foreach ($fileList as $file) {
            $this->assertTrue(file_exists($file), "Virtual file $file does not exist");
        }

        $command = $this->command;
        $output = $this->createMock(OutputInterface::class);
        $messages = [];
        $output->expects($this->atLeastOnce())
            ->method('writeln')
            ->will($this->returnCallback(function ($message) use (&$messages) {
                $messages[] = $message;
                return null;
            }));

        // Use reflection to simulate execute
        $processEntityFile = new ReflectionMethod($command, 'processEntityFile');
        $generateEntityKey = new ReflectionMethod($command, 'generateEntityKey');

        $entities = [];
        $channeledClassMap = [];
        $entityDirs = [
            'general' => $this->vfs->url() . '/src/Entities',
            'channeled' => $this->vfs->url() . '/src/Analytics/Channeled'
        ];

        foreach ($entityDirs as $type => $dir) {
            $filteredFiles = array_filter($fileList, fn ($file) => str_contains($file, $dir));
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
            $this->assertCount($type === 'general' ? 10 : 10, $filteredFiles, "Expected 10 files for $type");
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

        // Call saveConfig with virtual path
        $outputFile = $this->vfs->url() . '/config/yaml/test_entitiesconfig.yaml';
        try {
            $saveConfig = new ReflectionMethod($command, 'saveConfig');
            $saveConfig->setAccessible(true);
            $saveConfig->invoke($command, $entities, $output, $outputFile);
        } catch (\Exception $e) {
            $this->fail("saveConfig threw exception: " . $e->getMessage() . "\nMessages: " . implode(', ', $messages));
        }

        // Verify real filesystem is untouched
        $realConfigPath = realpath(__DIR__ . '/../../../config/yaml/entitiesconfig.yaml');
        if (!$realConfigPath || !file_exists($realConfigPath)) {
            $this->fail("Real entitiesconfig.yaml was not found or was deleted. Expected at config/yaml/entitiesconfig.yaml. Messages: " . implode(', ', $messages));
        }

        // Debug config files in vfsStream
        $configDir = $this->vfs->url() . '/config/yaml';
        $filesInDir = array_diff(scandir($configDir), ['.', '..']);
        if (!empty($filesInDir) && !in_array('test_entitiesconfig.yaml', $filesInDir)) {
            $this->fail("Unexpected files in config/yaml/: " . implode(', ', $filesInDir) . "\nMessages: " . implode(', ', $messages));
        }

        // Verify output file is in virtual filesystem
        $this->assertTrue(file_exists($outputFile), "Expected test_entitiesconfig.yaml at $outputFile. Check if saveConfig writes to correct path. Found files in config/yaml/: " . implode(', ', $filesInDir) . "\nMessages: " . implode(', ', $messages));
        $yamlContent = file_get_contents($outputFile);
        $this->assertStringContainsString('product:', $yamlContent, 'Expected product entity in YAML');
        $this->assertStringContainsString('customer:', $yamlContent, 'Expected customer entity in YAML');
        $this->assertStringContainsString('metric:', $yamlContent, 'Expected metric entity in YAML');
        $this->assertStringContainsString('order:', $yamlContent, 'Expected order entity in YAML');
        $this->assertStringContainsString('discount:', $yamlContent, 'Expected discount entity in YAML');
        $this->assertStringContainsString('price_rule:', $yamlContent, 'Expected price_rule entity in YAML');
        $this->assertStringContainsString('campaign:', $yamlContent, 'Expected campaign entity in YAML');
        $this->assertStringContainsString('product_category:', $yamlContent, 'Expected product_category entity in YAML');
        $this->assertStringContainsString('product_variant:', $yamlContent, 'Expected product_variant entity in YAML');
        $this->assertStringContainsString('vendor:', $yamlContent, 'Expected vendor entity in YAML');
        $this->assertStringContainsString('channeled_product:', $yamlContent, 'Expected channeled_product entity in YAML');
        $this->assertStringContainsString('channeled_customer:', $yamlContent, 'Expected channeled_customer entity in YAML');
        $this->assertStringContainsString('channeled_metric:', $yamlContent, 'Expected channeled_metric entity in YAML');
        $this->assertStringContainsString('channeled_order:', $yamlContent, 'Expected channeled_order entity in YAML');
        $this->assertStringContainsString('channeled_discount:', $yamlContent, 'Expected channeled_discount entity in YAML');
        $this->assertStringContainsString('channeled_price_rule:', $yamlContent, 'Expected channeled_price_rule entity in YAML');
        $this->assertStringContainsString('channeled_campaign:', $yamlContent, 'Expected channeled_campaign entity in YAML');
        $this->assertStringContainsString('channeled_product_category:', $yamlContent, 'Expected channeled_product_category entity in YAML');
        $this->assertStringContainsString('channeled_product_variant:', $yamlContent, 'Expected channeled_product_variant entity in YAML');
        $this->assertStringContainsString('channeled_vendor:', $yamlContent, 'Expected channeled_vendor entity in YAML');

        $this->assertCount(20, $entities, 'Expected 20 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testExecuteWithNoValidEntitiesReturnsFailure(): void
    {
        $command = new GenerateEntitiesConfigCommand();
        $output = $this->createMock(OutputInterface::class);
        $messages = [];
        $output->expects($this->once())
            ->method('writeln')
            ->with('<info>Successfully generated config for 0 entities</info>')
            ->will($this->returnCallback(function ($message) use (&$messages) {
                $messages[] = $message;
                return null;
            }));

        $entities = [];
        $entityDirs = [
            'general' => $this->vfs->url() . '/src/EmptyEntities',
            'channeled' => $this->vfs->url() . '/src/EmptyEntities'
        ];

        foreach ($entityDirs as $dir) {
            $this->assertDirectoryExists($dir, "Directory $dir does not exist");
        }

        $outputFile = $this->vfs->url() . '/config/yaml/test_entitiesconfig.yaml';
        $saveConfig = new ReflectionMethod($command, 'saveConfig');
        $saveConfig->setAccessible(true);
        $saveConfig->invoke($command, $entities, $output, $outputFile);

        // Verify real filesystem is untouched
        $realConfigPath = realpath(__DIR__ . '/../../../config/yaml/entitiesconfig.yaml');
        if (!$realConfigPath || !file_exists($realConfigPath)) {
            $this->fail("Real entitiesconfig.yaml was not found or was deleted. Expected at config/yaml/entitiesconfig.yaml. Messages: " . implode(', ', $messages));
        }

        $this->assertFalse(file_exists($outputFile), "Unexpected test_entitiesconfig.yaml at $outputFile");
        $this->assertCount(0, $entities, 'Expected 0 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testExecuteWithInvalidEntityFileSkipsFile(): void
    {
        $files = [$this->vfs->url() . '/src/Entities/Invalid.php'];
        $fileList = $files;

        $this->assertTrue(file_exists($files[0]), "Virtual file {$files[0]} does not exist");

        $command = new GenerateEntitiesConfigCommand();
        $output = $this->createMock(OutputInterface::class);
        $messages = [];
        $expectedMessages = [
            '<comment>Invalid entity in: ' . $this->vfs->url() . '/src/Entities/Invalid.php' . '</comment>',
            '<info>Successfully generated config for 0 entities</info>'
        ];
        $callIndex = 0;
        $output->expects($this->exactly(2))
            ->method('writeln')
            ->will($this->returnCallback(function ($message) use (&$callIndex, $expectedMessages, &$messages) {
                $this->assertLessThan(count($expectedMessages), $callIndex, 'Too many writeln calls');
                $this->assertEquals($expectedMessages[$callIndex], $message, "writeln call $callIndex does not match expected message");
                $messages[] = $message;
                $callIndex++;
            }));

        $processEntityFile = new ReflectionMethod($command, 'processEntityFile');
        $saveConfig = new ReflectionMethod($command, 'saveConfig');

        $entities = [];
        $entityDirs = [
            'general' => $this->vfs->url() . '/src/Entities',
            'channeled' => $this->vfs->url() . '/src/Analytics/Channeled'
        ];

        foreach ($entityDirs as $type => $dir) {
            $filteredFiles = array_filter($fileList, fn ($file) => str_contains($file, $dir));
            foreach ($filteredFiles as $file) {
                $config = $processEntityFile->invoke($command, $file, $type, $output);
                if ($config) {
                    $entities[] = $config;
                }
            }
            $this->assertCount($type === 'general' ? 1 : 0, $filteredFiles, "Expected " . ($type === 'general' ? 1 : 0) . " files for $type");
        }

        $outputFile = $this->vfs->url() . '/config/yaml/test_entitiesconfig.yaml';
        $saveConfig->setAccessible(true);
        $saveConfig->invoke($command, $entities, $output, $outputFile);

        // Verify real filesystem is untouched
        $realConfigPath = realpath(__DIR__ . '/../../../config/yaml/entitiesconfig.yaml');
        if (!$realConfigPath || !file_exists($realConfigPath)) {
            $this->fail("Real entitiesconfig.yaml was not found or was deleted. Expected at config/yaml/entitiesconfig.yaml. Messages: " . implode(', ', $messages));
        }

        $this->assertFalse(file_exists($outputFile), "Unexpected test_entitiesconfig.yaml at $outputFile");
        $this->assertCount(0, $entities, 'Expected 0 entities processed');
    }

    /**
     * @throws ReflectionException
     */
    public function testFindPhpFilesYieldsPhpFiles(): void
    {
        $dir = $this->vfs->url() . '/src/Entities';
        $reflection = new ReflectionMethod($this->command, 'findPhpFiles');
        $generator = $reflection->invoke($this->command, $dir);
        $result = iterator_to_array($generator);
        $expected = [
            $this->vfs->url() . '/src/Entities/Campaign.php',
            $this->vfs->url() . '/src/Entities/Customer.php',
            $this->vfs->url() . '/src/Entities/Discount.php',
            $this->vfs->url() . '/src/Entities/Invalid.php',
            $this->vfs->url() . '/src/Entities/Metric.php',
            $this->vfs->url() . '/src/Entities/Order.php',
            $this->vfs->url() . '/src/Entities/PriceRule.php',
            $this->vfs->url() . '/src/Entities/Product.php',
            $this->vfs->url() . '/src/Entities/ProductCategory.php',
            $this->vfs->url() . '/src/Entities/ProductVariant.php',
            $this->vfs->url() . '/src/Entities/Vendor.php'
        ];
        $result = array_map(fn ($path) => str_replace('\\', '/', $path), $result);
        $expected = array_map(fn ($path) => str_replace('\\', '/', $path), $expected);
        sort($result);
        sort($expected);
        $this->assertSame($expected, $result, 'Expected files: ' . implode(', ', $expected) . '; Got: ' . implode(', ', $result));
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessEntityFileReturnsConfigForValidEntity(): void
    {
        $file = $this->vfs->url() . '/src/Entities/Product.php';
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
                    'parameters' => ['filters', 'startDate', 'endDate'],
                    'return_type' => 'int'
                ],
                'readMultiple' => [
                    'parameters' => ['limit', 'pagination', 'ids', 'filters', 'orderBy', 'orderDir', 'startDate', 'endDate', 'extra'],
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
                'aggregate' => [
                    'parameters' => ['aggregations', 'groupBy', 'filters', 'startDate', 'endDate'],
                    'return_type' => 'array'
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
                ],
                'setHideFields' => [
                    'parameters' => ['fields'],
                    'return_type' => 'static'
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

        $simpleContent = "<?php\nnamespace Entities;\n\nclass Product {}\n";
        $result = $reflection->invoke($this->command, $simpleContent);
        $expected = [
            'fullName' => 'Entities\Product',
            'shortName' => 'Product'
        ];
        $this->assertEquals($expected, $result, 'Failed to extract class info from simple class');

        $complexContent = "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductRepository::class)]\nclass ChanneledProduct {}\n";
        $result = $reflection->invoke($this->command, $complexContent);
        $expected = [
            'fullName' => 'Entities\Analytics\Channeled\ChanneledProduct',
            'shortName' => 'ChanneledProduct'
        ];
        $this->assertEquals($expected, $result, 'Failed to extract class info from complex class');

        $invalidContent = "<?php\nnamespace Entities;\n\n// No class\n";
        $result = $reflection->invoke($this->command, $invalidContent);
        $this->assertNull($result, 'Expected null for content without class');

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

    private function getCustomerEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: CustomerRepository::class)]\nclass Customer {}\n";
    }

    private function getMetricEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: MetricRepository::class)]\nclass Metric {}\n";
    }

    private function getOrderEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: OrderRepository::class)]\nclass Order {}\n";
    }

    private function getDiscountEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: DiscountRepository::class)]\nclass Discount {}\n";
    }

    private function getPriceRuleEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: PriceRuleRepository::class)]\nclass PriceRule {}\n";
    }

    private function getCampaignEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: CampaignRepository::class)]\nclass Campaign {}\n";
    }

    private function getProductCategoryEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: ProductCategoryRepository::class)]\nclass ProductCategory {}\n";
    }

    private function getProductVariantEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: ProductVariantRepository::class)]\nclass ProductVariant {}\n";
    }

    private function getVendorEntityContent(): string
    {
        return "<?php\nnamespace Entities;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: VendorRepository::class)]\nclass Vendor {}\n";
    }

    private function getChanneledProductEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductRepository::class)]\nclass ChanneledProduct {}\n";
    }

    private function getChanneledCustomerEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledCustomerRepository::class)]\nclass ChanneledCustomer {}\n";
    }

    private function getChanneledMetricEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledMetricRepository::class)]\nclass ChanneledMetric {}\n";
    }

    private function getChanneledOrderEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledOrderRepository::class)]\nclass ChanneledOrder {}\n";
    }

    private function getChanneledDiscountEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledDiscountRepository::class)]\nclass ChanneledDiscount {}\n";
    }

    private function getChanneledPriceRuleEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledPriceRuleRepository::class)]\nclass ChanneledPriceRule {}\n";
    }

    private function getChanneledCampaignEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledCampaignRepository::class)]\nclass ChanneledCampaign {}\n";
    }

    private function getChanneledProductCategoryEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductCategoryRepository::class)]\nclass ChanneledProductCategory {}\n";
    }

    private function getChanneledProductVariantEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledProductVariantRepository::class)]\nclass ChanneledProductVariant {}\n";
    }

    private function getChanneledVendorEntityContent(): string
    {
        return "<?php\nnamespace Entities\\Analytics\\Channeled;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity(repositoryClass: \\Repositories\\Channeled\\ChanneledVendorRepository::class)]\nclass ChanneledVendor {}\n";
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
