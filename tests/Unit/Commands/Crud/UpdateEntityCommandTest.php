<?php

namespace Tests\Unit\Commands\Crud;

use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;
use Commands\Crud\UpdateEntityCommand;
use Controllers\CrudController;
use Doctrine\ORM\Exception\NotSupported;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

class UpdateEntityCommandTest extends TestCase
{
    private UpdateEntityCommand $command;
    private CommandTester $commandTester;
    private ?vfsStreamDirectory $vfs;
    private $crudController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->crudController = $this->createMock(CrudController::class);
        $this->command = new UpdateEntityCommand($this->crudController);
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
        $this->assertEquals('app:update', $this->command->getName());
        $this->assertEquals('Update an entity record', $this->command->getDescription());
        $this->assertEquals('This command allows you to get update an entity record', $this->command->getHelp());
        $this->assertEquals(['app:edit'], $this->command->getAliases());
        $this->assertFalse($this->command->isHidden());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('entity'));
        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('data'));
    }

    public function testExecuteWithValidOptionsReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity updated successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('update'),
                $this->equalTo('123'),
                $this->equalTo('{"name":"Updated Product"}')
            )
            ->willReturn($response);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--id' => '123',
            '--data' => '{"name":"Updated Product"}'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity updated successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithoutDataOptionSucceeds(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity updated successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('update'),
                $this->equalTo('123'),
                $this->equalTo(null)
            )
            ->willReturn($response);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--id' => '123'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity updated successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithoutEntityOptionFails(): void
    {
        // Execute command without --entity
        $this->expectException(\TypeError::class);

        $this->commandTester->execute([
            '--id' => '123',
            '--data' => '{"name":"Updated Product"}'
        ]);
    }

    public function testExecuteWithoutIdOptionSucceeds(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity updated successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('update'),
                $this->equalTo(null),
                $this->equalTo('{"name":"Updated Product"}')
            )
            ->willReturn($response);

        // Execute command
        $this->commandTester->execute([
            '--entity' => 'product',
            '--data' => '{"name":"Updated Product"}'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Entity updated successfully', $output);
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
            '--entity' => 'product',
            '--id' => '123'
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
            '--entity' => 'product',
            '--id' => '123'
        ]);
    }
}
