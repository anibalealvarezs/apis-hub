<?php

namespace Tests\Unit\Commands\Crud;

use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;
use Commands\Crud\ReadEntityCommand;
use Controllers\CrudController;
use Doctrine\ORM\Exception\NotSupported;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

class ReadEntityCommandTest extends TestCase
{
    private ReadEntityCommand $command;
    private CommandTester $commandTester;
    private ?vfsStreamDirectory $vfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ReadEntityCommand();
        $this->commandTester = new CommandTester($this->command);

        // Set up virtual file system with vfsStream for consistency
        $structure = [
            'src' => [
                'Entities' => []
            ],
            'config' => [
                'yaml' => []
            ]
        ];
        $this->vfs = vfsStream::setup('project', null, $structure);

        // Verify directory existence
        $entitiesDir = vfsStream::url('project/src/Entities');
        $configDir = vfsStream::url('project/config/yaml');
        $this->assertDirectoryExists($entitiesDir, 'Entities directory missing');
        $this->assertDirectoryExists($configDir, 'Config directory missing');
    }

    protected function tearDown(): void
    {
        $this->vfs = null;
        parent::tearDown();
    }

    public function testConfigureSetsCorrectAttributes(): void
    {
        $this->assertEquals('app:read', $this->command->getName());
        $this->assertEquals('Read entity records', $this->command->getDescription());
        $this->assertEquals('This command allows you to get entities data', $this->command->getHelp());
        $this->assertEquals(['app:get'], $this->command->getAliases());
        $this->assertFalse($this->command->isHidden());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('entity'));
        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('filters'));

        $entityOption = $definition->getOption('entity');
        $this->assertEquals('e', $entityOption->getShortcut());
        $this->assertTrue($entityOption->isValueRequired());
        $this->assertEquals('The entity which the data will be retrieved from', $entityOption->getDescription());

        $idOption = $definition->getOption('id');
        $this->assertEquals('i', $idOption->getShortcut());
        $this->assertTrue($idOption->isValueOptional());
        $this->assertEquals('The id of the entity record', $idOption->getDescription());

        $filtersOption = $definition->getOption('filters');
        $this->assertEquals('f', $filtersOption->getShortcut());
        $this->assertTrue($filtersOption->isValueOptional());
        $this->assertEquals('The fields which will be used to filter the data', $filtersOption->getDescription());
    }

    /**
     */
    public function testExecuteWithIdReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity read successfully');

        // Mock CrudController
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('read'),
                $this->equalTo('123')
            )
            ->willReturn($response);

        // Use test-specific subclass to inject mock
        $this->command = new TestReadEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--id' => '123'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity read successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     */
    public function testExecuteWithFiltersReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entities listed successfully');

        // Mock CrudController
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
                $this->equalTo(null),
                $this->equalTo('{"name":"Test Product"}')
            )
            ->willReturn($response);

        // Use test-specific subclass to inject mock
        $this->command = new TestReadEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--filters' => '{"name":"Test Product"}'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entities listed successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     */
    public function testExecuteWithoutIdOrFiltersReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entities listed successfully');

        // Mock CrudController
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willReturn($response);

        // Use test-specific subclass to inject mock
        $this->command = new TestReadEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entities listed successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithoutEntityOptionFails(): void
    {
        // Execute command without --entity
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/Controllers\\\CrudController::__invoke\(\): Argument #1 \(\$entity\) must be of type string, null given/');

        $this->commandTester->execute([
            '--id' => '123',
            '--filters' => '{"name":"Test Product"}'
        ]);
    }

    /**
     */
    public function testExecuteHandlesNotSupportedException(): void
    {
        // Mock CrudController to throw NotSupported
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willThrowException(new NotSupported('Entity not supported'));

        // Use test-specific subclass to inject mock
        $this->command = new TestReadEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Expect exception
        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage('Entity not supported');

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);
    }

    /**
     */
    public function testExecuteHandlesReflectionException(): void
    {
        // Mock CrudController to throw ReflectionException
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willThrowException(new ReflectionException('Reflection error'));

        // Use test-specific subclass to inject mock
        $this->command = new TestReadEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Expect exception
        $this->expectException(ReflectionException::class);
        $this->expectExceptionMessage('Reflection error');

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);
    }
}

/**
 * Test-specific subclass to inject a mock CrudController.
 */
class TestReadEntityCommand extends ReadEntityCommand
{
    private CrudController $crudController;

    public function __construct(CrudController $crudController)
    {
        parent::__construct();
        $this->crudController = $crudController;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('id')) {
            $result = ($this->crudController)(
                entity: $input->getOption('entity'),
                method: 'read',
                id: $input->getOption('id'),
            );
        } else {
            $result = ($this->crudController)(
                entity: $input->getOption('entity'),
                method: 'list',
                body: $input->getOption('filters'),
            );
        }

        $output->writeln('<info>' . $result->getContent() . '</info>');
        return Command::SUCCESS;
    }
}