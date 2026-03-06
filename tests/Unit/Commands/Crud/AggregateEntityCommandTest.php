<?php

namespace Tests\Unit\Commands\Crud;

use Commands\Crud\AggregateEntityCommand;
use Controllers\CrudController;
use Controllers\ChanneledCrudController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

class AggregateEntityCommandTest extends TestCase
{
    private AggregateEntityCommand $command;
    private CommandTester $commandTester;
    private $crudController;
    private $channeledCrudController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->crudController = $this->createMock(CrudController::class);
        $this->channeledCrudController = $this->createMock(ChanneledCrudController::class);
        $this->command = new AggregateEntityCommand($this->crudController, $this->channeledCrudController);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testConfigureSetsCorrectAttributes(): void
    {
        $this->assertEquals('app:aggregate', $this->command->getName());
        $this->assertEquals('Aggregate entity records', $this->command->getDescription());
        $this->assertEquals(['app:sum', 'app:avg'], $this->command->getAliases());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('entity'));
        $this->assertTrue($definition->hasOption('aggregations'));
        $this->assertTrue($definition->hasOption('channel'));
        $this->assertTrue($definition->hasOption('group-by'));
        $this->assertTrue($definition->hasOption('filters'));
        $this->assertTrue($definition->hasOption('params'));
    }

    public function testExecuteWithoutChannelCallsCrudController(): void
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('{"data": [{"total": 100}], "status": "success"}');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('product'),
                $this->equalTo('aggregate'),
                $this->equalTo(null), // $id
                $this->equalTo(null), // $body
                $this->callback(function ($params) {
                    return is_array($params) && 
                           $params['aggregations'] === ['total' => 'SUM(price)'] &&
                           $params['groupBy'] === ['category'];
                })
            )
            ->willReturn($response);

        $this->commandTester->execute([
            '--entity' => 'product',
            '--aggregations' => '{"total": "SUM(price)"}',
            '--group-by' => 'category'
        ]);

        $this->assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithChannelCallsChanneledCrudController(): void
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('{"data": [{"total": 50}], "status": "success"}');

        $this->channeledCrudController->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('channeled_metric'),
                $this->equalTo('shopify'),
                $this->equalTo('aggregate'),
                $this->equalTo(null), // $id
                $this->equalTo('{"type": "sale"}'), // $body
                $this->callback(function ($params) {
                    return is_array($params) &&
                           $params['aggregations'] === ['revenue' => 'SUM(value)'] &&
                           $params['startDate'] === '2024-01-01' &&
                           $params['endDate'] === '2024-01-31';
                })
            )
            ->willReturn($response);

        $this->commandTester->execute([
            '--entity' => 'channeled_metric',
            '--channel' => 'shopify',
            '--aggregations' => '{"revenue": "SUM(value)"}',
            '--filters' => '{"type": "sale"}',
            '--start-date' => '2024-01-01',
            '--end-date' => '2024-01-31'
        ]);

        $this->assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testExecuteHandlesFailureResponse(): void
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(400);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('{"error": "Invalid aggregations"}');

        $this->crudController->expects($this->once())
            ->method('__invoke')
            ->willReturn($response);

        $this->commandTester->execute([
            '--entity' => 'product',
            '--aggregations' => '{"total": "INVALID(price)"}'
        ]);

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid aggregations', $this->commandTester->getDisplay());
    }
}
