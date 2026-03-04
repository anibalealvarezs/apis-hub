<?php

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\CacheEntityCommand;
use Controllers\CacheController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

class CacheEntityCommandTest extends TestCase
{
    private CacheEntityCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CacheEntityCommand();
        $this->commandTester = new CommandTester($this->command);
    }

    public function testConfigureSetsCorrectAttributes(): void
    {
        $this->assertEquals('analytics:cache', $this->command->getName());
        $this->assertEquals('Schedule a caching job for an analytics entity.', $this->command->getDescription());
        $this->assertEquals('This command allows you to schedule a caching job for a specific entity and channel, identical to the API endpoint logic.', $this->command->getHelp());
        $this->assertEquals(['app:cache'], $this->command->getAliases());
        $this->assertFalse($this->command->isHidden());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasArgument('channel'));
        $this->assertTrue($definition->hasArgument('entity'));
        $this->assertTrue($definition->hasOption('data'));
        $this->assertTrue($definition->hasOption('params'));

        $channelArgument = $definition->getArgument('channel');
        $this->assertTrue($channelArgument->isRequired());
        $this->assertEquals('The channel to cache from (e.g. shopify, klaviyo)', $channelArgument->getDescription());

        $entityArgument = $definition->getArgument('entity');
        $this->assertTrue($entityArgument->isRequired());
        $this->assertEquals('The entity to cache (e.g. products, customers)', $entityArgument->getDescription());

        $dataOption = $definition->getOption('data');
        $this->assertEquals('d', $dataOption->getShortcut());
        $this->assertTrue($dataOption->isValueOptional());
        $this->assertEquals('The JSON body data to pass to the request', $dataOption->getDescription());

        $paramsOption = $definition->getOption('params');
        $this->assertEquals('p', $paramsOption->getShortcut());
        $this->assertTrue($paramsOption->isValueOptional());
        $this->assertEquals('The JSON or query string parameters to pass to the request', $paramsOption->getDescription());
    }

    public function testExecuteWithValidOptionsReturnsSuccess(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->any())
            ->method('getContent')
            ->willReturn('{"message":"Caching job successfully scheduled in background."}');

        // Mock CacheController
        $controller = $this->createMock(CacheController::class);
        $controller->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('shopify'),
                $this->equalTo('products'),
                $this->equalTo('{"test":"data"}'),
                $this->equalTo(['limit' => 50, 'filters' => ['status' => 'active']])
            )
            ->willReturn($response);

        // Inject mock
        $this->command = new CacheEntityCommand($controller);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            'channel' => 'shopify',
            'entity' => 'products',
            '--data' => '{"test":"data"}',
            '--params' => json_encode(['limit' => 50, 'filters' => ['status' => 'active']])
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Caching job successfully scheduled', $output);
        $this->assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testExecuteHandlesFailure(): void
    {
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(409);
        $response->expects($this->any())
            ->method('getContent')
            ->willReturn('{"error":"There is already an active caching process for this endpoint."}');

        // Mock CacheController
        $controller = $this->createMock(CacheController::class);
        $controller->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('klaviyo'),
                $this->equalTo('customers'),
                $this->equalTo(null),
                $this->equalTo([])
            )
            ->willReturn($response);

        // Inject mock
        $this->command = new CacheEntityCommand($controller);
        $this->commandTester = new CommandTester($this->command);

        // Execute command
        $this->commandTester->execute([
            'channel' => 'klaviyo',
            'entity' => 'customers'
        ]);

        // Verify output and status
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('There is already an active caching process', $output);
        $this->assertStringContainsString('Error (409)', $output);
        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
    }
}
