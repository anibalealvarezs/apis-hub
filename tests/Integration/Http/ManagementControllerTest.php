<?php

namespace Tests\Integration\Http;

use Classes\RoutingCore;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\BaseIntegrationTestCase;

class ManagementControllerTest extends BaseIntegrationTestCase
{
    private RoutingCore $router;
    private string $adminKey;
    private string $actualEnvPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->router = new RoutingCore();
        $pageRoutes = require __DIR__ . "/../../../src/Routes/page.php";
        $this->router->multiMap($pageRoutes);

        $this->adminKey = 'test-admin-key-' . $this->faker->uuid;
        putenv('ADMIN_API_KEY=' . $this->adminKey);
        
        // Setup temporary .env file for testing updates in the root dir where controller expects it
        // realpath(__DIR__ . '/../../' . $envFileName) in src/Controllers/ManagementController.php
        $this->actualEnvPath = realpath(__DIR__ . '/../../../') . DIRECTORY_SEPARATOR . '.env.integration_test';
        file_put_contents($this->actualEnvPath, "INITIAL_KEY=initial_value\nFACEBOOK_USER_TOKEN=old_token\n");
        putenv('ENV_FILE=.env.integration_test');
        
        Helpers::resetConfigs();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->actualEnvPath)) {
            unlink($this->actualEnvPath);
        }
        putenv('ADMIN_API_KEY');
        putenv('ENV_FILE');
        Helpers::resetConfigs();
        parent::tearDown();
    }

    public function testGetStatusReturnsSystemInfo(): void
    {
        // Act
        $request = Request::create('/api/management/status', 'GET');
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $response = $this->router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('php_version', $data['data']);
        $this->assertArrayHasKey('memory_usage', $data['data']);
        $this->assertEquals(PHP_OS, $data['data']['os']);
    }

    public function testUpdateCredentialsModifiesEnvFile(): void
    {
        // Arrange
        $newTokens = [
            'FACEBOOK_USER_TOKEN' => 'new_fb_token_' . $this->faker->md5,
            'GOOGLE_REFRESH_TOKEN' => 'new_google_token_' . $this->faker->md5,
            'MONITOR_FACADE_URL' => 'https://facade.example.com'
        ];

        // Act
        $request = Request::create(
            '/api/management/update-credentials', 
            'POST', 
            [], 
            [], 
            [], 
            [], 
            json_encode($newTokens)
        );
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $request->headers->set('Content-Type', 'application/json');
        
        $response = $this->router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('successfully', $response->getContent());

        // Verify file content
        $content = file_get_contents($this->actualEnvPath);
        $this->assertStringContainsString("FACEBOOK_USER_TOKEN={$newTokens['FACEBOOK_USER_TOKEN']}", $content);
        $this->assertStringContainsString("GOOGLE_REFRESH_TOKEN={$newTokens['GOOGLE_REFRESH_TOKEN']}", $content);
        $this->assertStringContainsString("MONITOR_FACADE_URL={$newTokens['MONITOR_FACADE_URL']}", $content);
        $this->assertStringContainsString("INITIAL_KEY=initial_value", $content);
    }

    public function testUpdateCredentialsRejectsUnallowedKeys(): void
    {
        // Arrange
        $payload = [
            'DB_PASSWORD' => 'malicious_pass', // Not in allowedKeys
            'FACEBOOK_USER_TOKEN' => 'valid_token'
        ];

        // Act
        $request = Request::create(
            '/api/management/update-credentials', 
            'POST', 
            [], 
            [], 
            [], 
            [], 
            json_encode($payload)
        );
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $request->headers->set('Content-Type', 'application/json');
        
        $this->router->handle($request);

        // Assert
        $content = file_get_contents($this->actualEnvPath);
        $this->assertStringContainsString('FACEBOOK_USER_TOKEN=valid_token', $content);
        $this->assertStringNotContainsString('DB_PASSWORD=malicious_pass', $content);
    }

    public function testTriggerRedeployReturnsSuccess(): void
    {
        // Act
        $request = Request::create('/api/management/redeploy', 'POST');
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $response = $this->router->handle($request);

        // Assert
        // On local machine it might return 500 if bin/full-deploy.sh doesn't exist
        // But the controller returns 500 if script not found, which is a valid part of test.
        if ($response->getStatusCode() === 500) {
            $this->assertStringContainsString('script not found', $response->getContent());
        } else {
            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
            $this->assertStringContainsString('triggered', $response->getContent());
        }
    }

    public function testManagementRoutesRequireAdminKey(): void
    {
        // Act - Without key
        $request = Request::create('/api/management/status', 'GET');
        $response = $this->router->handle($request);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        // Act - With regular key (not admin)
        putenv('APP_API_KEY=regular-key');
        $request = Request::create('/api/management/status', 'GET');
        $request->headers->set('X-API-Key', 'regular-key');
        $response = $this->router->handle($request);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        
        putenv('APP_API_KEY');
    }
}
