<?php

namespace Tests\Integration\Http;

use Classes\RoutingCore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\BaseIntegrationTestCase;

class RoutingCoreTest extends BaseIntegrationTestCase
{
    public function testRoutingCoreRejectsRequestsWithoutApiKeyWhenConfigured(): void
    {
        // Arrange
        $apiKey = $this->faker->uuid;
        putenv('APP_API_KEY=' . $apiKey);

        $router = new RoutingCore();
        $router->map('/test-route', 'GET', function (?string $body, ?array $params, ...$args) {
            return new Response(json_encode(['success' => true]));
        });

        // Act - Request without API Key
        $requestNoKey = Request::create('/test-route', 'GET');
        $responseNoKey = $router->handle($requestNoKey);

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $responseNoKey->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $responseNoKey->getContent());

        // Act - Request with wrong API Key
        $requestWrongKey = Request::create('/test-route', 'GET');
        $requestWrongKey->headers->set('X-API-Key', 'wrong-' . $this->faker->word);
        $responseWrongKey = $router->handle($requestWrongKey);

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $responseWrongKey->getStatusCode());
        
        // Act - Request with correct API Key
        $requestRightKey = Request::create('/test-route', 'GET');
        $requestRightKey->headers->set('X-API-Key', $apiKey);
        $responseRightKey = $router->handle($requestRightKey);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $responseRightKey->getStatusCode());
        $this->assertStringContainsString('success', $responseRightKey->getContent());
        
        // Clean up
        putenv('APP_API_KEY');
    }

    public function testRoutingCoreHandlesMissingRoutesGracefully(): void
    {
        // Arrange
        putenv('APP_API_KEY='); // No key required for this test, or we pass one
        $router = new RoutingCore();

        // Act
        $request = Request::create('/non-existent-route', 'GET');
        $response = $router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        
        // Clean up
        putenv('APP_API_KEY');
    }
}
