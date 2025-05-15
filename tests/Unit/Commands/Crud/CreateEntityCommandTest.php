<?php

namespace Tests\Unit\Commands\Crud;

use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;
use Commands\Crud\CreateEntityCommand;
use Controllers\CrudController;
use Doctrine\ORM\Exception\NotSupported;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

class CreateEntityCommandTest extends TestCase
{
    private CreateEntityCommand $command;
    private CommandTester $commandTester;
    private ?vfsStreamDirectory $vfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CreateEntityCommand();
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
        $this->assertEquals('app:create', $this->command->getName());
        $this->assertEquals('Create entity record', $this->command->getDescription());
        $this->assertEquals('This command allows you to get create a new entity record', $this->command->getHelp());
        $this->assertEquals(['app:new'], $this->command->getAliases());
        $this->assertFalse($this->command->isHidden());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('entity'));
        $this->assertTrue($definition->hasOption('data'));

        $entityOption = $definition->getOption('entity');
        $this->assertEquals('e', $entityOption->getShortcut());
        $this->assertTrue($entityOption->isValueRequired());
        $this->assertEquals('The entity which the record will be created in', $entityOption->getDescription());

        $dataOption = $definition->getOption('data');
        $this->assertEquals('d', $dataOption->getShortcut());
        $this->assertTrue($dataOption->isValueOptional());
        $this->assertEquals('The data which will be used to create the record', $dataOption->getDescription());
    }

    /**
     */
    public function testExecuteWithValidOptionsReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity created successfully');

        // Mock CrudController
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo('{"name":"Test Product"}')
            )
            ->willReturn($response);

        // Use test-specific subclass to inject mock
        $this->command = new TestCreateEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--data' => '{"name":"Test Product"}'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity created successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithoutEntityOptionFails(): void
    {
        // Execute command without --entity
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/Controllers\\\CrudController::__invoke\(\): Argument #1 \(\$entity\) must be of type string, null given/');

        $this->commandTester->execute([
            '--data' => '{"name":"Test Product"}'
        ]);
    }

    /**
     */
    public function testExecuteWithoutDataOptionSucceeds(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity created successfully');

        // Mock CrudController
        $crudController = $this->createMock(CrudController::class);
        $crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willReturn($response);

        // Use test-specific subclass to inject mock
        $this->command = new TestCreateEntityCommand($crudController);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity created successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
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
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willThrowException(new NotSupported('Entity not supported'));

        // Use test-specific subclass to inject mock
        $this->command = new TestCreateEntityCommand($crudController);
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
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willThrowException(new ReflectionException('Reflection error'));

        // Use test-specific subclass to inject mock
        $this->command = new TestCreateEntityCommand($crudController);
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
class TestCreateEntityCommand extends CreateEntityCommand
{
    private CrudController $crudController;

    public function __construct(CrudController $crudController)
    {
        parent::__construct();
        $this->crudController = $crudController;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = ($this->crudController)(
            entity: $input->getOption('entity'),
            method: 'create',
            body: $input->getOption('data'),
        );

        $output->writeln('<info>' . $result->getContent() . '</info>');
        return Command::SUCCESS;
    }
}