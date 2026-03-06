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
    private $crudController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->crudController = $this->createMock(CrudController::class);
        $this->command = new CreateEntityCommand($this->crudController);
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
    }

    public function testExecuteWithValidOptionsReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity created successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo('{"name":"Test Product"}')
            )
            ->willReturn($response);

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
        // The message comes from the invoked controller which is a MockObject here, but since we are calling it as $controller() 
        // it still triggers PHP's type check for __invoke if we were using real classes.
        // However, with a Mock, it might behave differently depending on how it's called.
        // In the Command: $result = ($controller)(entity: $input->getOption('entity'), ...)
        // If $controller is a mock, PHP still checks the argument types of the original class's __invoke method.

        $this->commandTester->execute([
            '--data' => '{"name":"Test Product"}'
        ]);
    }

    public function testExecuteWithoutDataOptionSucceeds(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity created successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('create'),
                $this->equalTo(null),
                $this->equalTo(null)
            )
            ->willReturn($response);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity created successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteHandlesNotSupportedException(): void
    {
        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->willThrowException(new NotSupported('Entity not supported'));

        // Expect exception
        $this->expectException(NotSupported::class);
        $this->expectExceptionMessage('Entity not supported');

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);
    }

    public function testExecuteHandlesReflectionException(): void
    {
        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->willThrowException(new ReflectionException('Reflection error'));

        // Expect exception
        $this->expectException(ReflectionException::class);
        $this->expectExceptionMessage('Reflection error');

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product'
        ]);
    }
}
