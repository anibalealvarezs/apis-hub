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
    private $crudController;
    private $channeledCrudController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->crudController = $this->createMock(CrudController::class);
        $this->channeledCrudController = $this->createMock(\Controllers\ChanneledCrudController::class);
        $this->command = new ReadEntityCommand($this->crudController, $this->channeledCrudController);
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
        $this->assertEquals('app:read', $this->command->getName());
        $this->assertEquals('Read entity records', $this->command->getDescription());
        $this->assertEquals('This command allows you to get entities data', $this->command->getHelp());
        $this->assertEquals(['app:get'], $this->command->getAliases());
        $this->assertFalse($this->command->isHidden());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('entity'));
        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('filters'));
    }

    public function testExecuteWithIdReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entity read successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('read'),
                $this->equalTo('123')
            )
            ->willReturn($response);

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

    public function testExecuteWithFiltersReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entities listed successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
                $this->equalTo(null),
                $this->equalTo('{"name":"Test Product"}')
            )
            ->willReturn($response);

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

    public function testExecuteWithoutIdOrFiltersReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('Entities listed successfully');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('list'),
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
        $this->assertStringContainsString('Entities listed successfully', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithoutEntityOptionFails(): void
    {
        // Execute command without --entity
        $this->expectException(\TypeError::class);

        $this->commandTester->execute([
            '--id' => '123',
            '--filters' => '{"name":"Test Product"}'
        ]);
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
