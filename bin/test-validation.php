<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider;
use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;

echo "========================================\n";
echo "DEBUGGING GOOGLE AUTHENTICATION\n";
echo "========================================\n";

try {
    \Helpers\Helpers::getProjectConfig(); // Load standard ENV
} catch (\Exception $e) {
    echo "Warning: Error loading project config: " . $e->getMessage() . "\n";
}

echo "1. Checking GOOGLE_TOKEN_PATH Environment: " . ($_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: "NULL") . "\n";

echo "\n2. Instantiating GoogleAuthProvider...\n";
$authProvider = new GoogleAuthProvider();
$config = $authProvider->getConfig();

if (empty($config)) {
    echo "❌ ERROR: authProvider->getConfig() returned EMPTY! The file was not read correctly.\n";
    echo "   Using path: " . (new ReflectionProperty(get_class($authProvider), 'filePath'))->getValue($authProvider) . "\n";
    exit(1);
}

echo "✅ SUCCESS: authProvider->getConfig() loaded " . count($config) . " keys.\n";
echo "Keys found: " . implode(', ', array_keys($config)) . "\n";

if (isset($config['google_auth'])) {
    echo "✅ SUCCESS: 'google_auth' key is present.\n";
    $googleAuth = $config['google_auth'];
    echo "   Access Token length: " . strlen($googleAuth['access_token'] ?? '') . "\n";
    echo "   Refresh Token length: " . strlen($googleAuth['refresh_token'] ?? '') . "\n";
} else {
    echo "❌ ERROR: 'google_auth' NOT found inside the tokens json!\n";
}

echo "\n3. Checking DriverFactory initialization...\n";
$allConfigs = \Helpers\Helpers::getChannelsConfig();
$chanConfig = $allConfigs['google_search_console'] ?? [];

echo "   Channel Config has " . count($chanConfig) . " keys.\n";
$chanConfig = array_replace_recursive($allConfigs['google'] ?? [], $chanConfig);
echo "   Merged Config has " . count($chanConfig) . " keys.\n";

echo "\n4. Instantiating SearchConsoleDriver manually...\n";
$driver = new SearchConsoleDriver($authProvider, null, $chanConfig);
$api = $driver->getApi();

echo "   API Client instantiated.\n";

$reflection = new ReflectionClass($api);
$refreshTokenProp = $reflection->getProperty('refreshToken');
$refreshTokenProp->setAccessible(true);
$tokenVal = $refreshTokenProp->getValue($api);

echo "   SearchConsoleApi RefreshToken length injected: " . strlen($tokenVal ?? '') . "\n";

if (empty($tokenVal)) {
    echo "❌ ERROR: API failed to inject the refresh token!\n";
} else {
    echo "✅ SUCCESS: API correctly received the refresh token.\n";
}

echo "\n5. Validating Authentication against Google...\n";
$validation = $driver->validateAuthentication();

echo "Result:\n";
print_r($validation);

echo "\n========================================\n";
