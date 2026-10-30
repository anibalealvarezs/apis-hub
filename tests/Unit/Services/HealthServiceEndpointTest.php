<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Services\HealthService;

class HealthServiceEndpointTest extends TestCase
{
    public function testHeartbeatAcceptsEitherOriginOrCompleteEndpoint(): void
    {
        foreach (['https://example.test', 'https://example.test/', 'https://example.test/api/heartbeat', 'https://example.test/api/heartbeat/'] as $url) {
            self::assertSame('https://example.test/api/heartbeat', HealthService::heartbeatUrl($url));
        }
    }

    public function testContainerProbeUsesServicePortInsteadOfPublishedHostPort(): void
    {
        $environment = ['MCP_HOST' => null, 'MCP_PORT' => '11191'];
        self::assertSame(['host' => 'mcp', 'port' => 3000], HealthService::getMcpEndpoint(true, $environment));
        self::assertSame(['host' => '127.0.0.1', 'port' => 11191], HealthService::getMcpEndpoint(false, $environment));
        self::assertSame(['host' => 'custom-mcp', 'port' => 3001], HealthService::getMcpEndpoint(true, ['MCP_HOST' => 'custom-mcp', 'MCP_INTERNAL_PORT' => '3001']));
    }
}
