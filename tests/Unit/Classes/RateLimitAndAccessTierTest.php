<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use Classes\RoutingCore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitAndAccessTierTest extends TestCase
{
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function testCheckRateLimitReturns403ForbiddenWhenLimitIsZeroForNonAdmin(): void
    {
        $routingCore = new RoutingCore();

        // Simulate non-admin request with rate limit set to 0 (Free / Pro tier)
        putenv('API_RATE_LIMIT_PER_MINUTE=0');

        $request = Request::create('/api/v1/ping', 'GET');
        $request->headers->set('X-API-Key', 'non-admin-sample-key');

        /** @var Response|null $response */
        $response = $this->invokePrivateMethod($routingCore, 'checkRateLimit', [$request]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Forbidden', $data['error']);
        $this->assertStringContainsString('API access is not included in your current subscription tier', $data['message']);

        // Clean up env
        putenv('API_RATE_LIMIT_PER_MINUTE');
    }

    public function testShouldRateLimitSkipsInternalHeartbeatAndWhitelistedPrefixes(): void
    {
        $routingCore = new RoutingCore();

        // Health check should bypass rate limiting
        $heartbeatRequest = Request::create('/api/heartbeat', 'GET');
        $this->assertFalse($this->invokePrivateMethod($routingCore, 'shouldRateLimit', [$heartbeatRequest]));

        // Monitoring and docs prefixes should bypass rate limiting
        $docsRequest = Request::create('/docs', 'GET');
        $this->assertFalse($this->invokePrivateMethod($routingCore, 'shouldRateLimit', [$docsRequest]));

        $monitoringRequest = Request::create('/monitoring', 'GET');
        $this->assertFalse($this->invokePrivateMethod($routingCore, 'shouldRateLimit', [$monitoringRequest]));
    }
}
