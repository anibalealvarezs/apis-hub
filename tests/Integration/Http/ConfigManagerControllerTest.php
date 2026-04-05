<?php

namespace Tests\Integration\Http;

use Classes\RoutingCore;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\Integration\BaseIntegrationTestCase;

class ConfigManagerControllerTest extends BaseIntegrationTestCase
{
    private RoutingCore $router;
    private string $adminKey;
    private string $tempConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->router = new RoutingCore();
        $pageRoutes = require __DIR__ . "/../../../src/Routes/page.php";
        $this->router->multiMap($pageRoutes);

        $this->adminKey = 'test-admin-key-' . $this->faker->uuid;
        putenv('ADMIN_API_KEY=' . $this->adminKey);
        
        // Setup temporary config directory
        $this->tempConfigDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apis_hub_config_test_' . uniqid();
        mkdir($this->tempConfigDir . DIRECTORY_SEPARATOR . 'channels', 0777, true);
        mkdir($this->tempConfigDir . DIRECTORY_SEPARATOR . 'yaml', 0777, true);
        putenv('CONFIG_DIR=' . $this->tempConfigDir);
        
        // Copy base config files to ensure DB etc. work
        $realConfigDir = realpath(__DIR__ . '/../../../config');
        foreach (['database.yaml', 'security.yaml', 'app.yaml'] as $mFile) {
            if (file_exists($realConfigDir . '/' . $mFile)) {
                copy($realConfigDir . '/' . $mFile, $this->tempConfigDir . '/' . $mFile);
            }
        }

        // Create dummy channel config files for isolation
        file_put_contents($this->tempConfigDir . '/channels/google_search_console.yaml', Yaml::dump(['channels' => ['google_search_console' => ['enabled' => true, 'sites' => []]]]));
        file_put_contents($this->tempConfigDir . '/channels/facebook.yaml', Yaml::dump(['channels' => ['facebook' => ['cache_chunk_size' => '1 week']]]));
        
        Helpers::resetConfigs();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempConfigDir);
        putenv('ADMIN_API_KEY');
        putenv('CONFIG_DIR');
        Helpers::resetConfigs();
        parent::tearDown();
    }

    private function removeDirectory($dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testFetchAssetsReturnsConfig(): void
    {
        // Act
        $request = Request::create('/api/config-manager/assets', 'GET');
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $response = $this->router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('config', $data);
        $this->assertEquals('1 week', $data['config']['fb_cache_chunk_size']);
    }

    public function testUpdateConfigModifiesYamlFiles(): void
    {
        // Arrange
        $payload = [
            'type' => 'facebook',
            'cache_chunk_size' => '2 weeks',
            'enabled' => true,
            'assets' => [
                'pages' => [
                    ['id' => '123', 'title' => 'Test Page', 'enabled' => true]
                ]
            ]
        ];

        // Act
        $request = Request::create(
            '/api/config-manager/update', 
            'POST', 
            [], 
            [], 
            [], 
            [], 
            json_encode($payload)
        );
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $request->headers->set('Content-Type', 'application/json');
        
        $response = $this->router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        // Verify YAML changes
        $fbGlobal = Yaml::parseFile($this->tempConfigDir . '/channels/facebook.yaml');
        $this->assertEquals('2 weeks', $fbGlobal['channels']['facebook']['cache_chunk_size']);

        $fbOrg = Yaml::parseFile($this->tempConfigDir . '/channels/facebook_organic.yaml');
        $this->assertCount(1, $fbOrg['channels']['facebook_organic']['pages']);
        $this->assertEquals('123', $fbOrg['channels']['facebook_organic']['pages'][0]['id']);
    }

    public function testUpdateGlobalConfigModifiesAppYaml(): void
    {
        // Arrange
        $payload = [
            'type' => 'global',
            'jobs_timeout_hours' => 12,
            'cache_raw_metrics' => true
        ];

        // Act
        $request = Request::create(
            '/api/config-manager/update', 
            'POST', 
            [], 
            [], 
            [], 
            [], 
            json_encode($payload)
        );
        $request->headers->set('X-Admin-API-Key', $this->adminKey);
        $request->headers->set('Content-Type', 'application/json');
        
        $response = $this->router->handle($request);

        // Assert
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        // Verify app.yaml
        $appYaml = Yaml::parseFile($this->tempConfigDir . '/app.yaml');
        $this->assertEquals(12, $appYaml['jobs']['timeout_hours']);
        $this->assertTrue($appYaml['analytics']['cache_raw_metrics']);
    }
}
