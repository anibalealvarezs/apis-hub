<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Manual .env loader (to match project architecture)
$dotEnv = __DIR__ . '/../.env';
if (file_exists($dotEnv)) {
    $lines = file($dotEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!getenv($name)) putenv("$name=$value");
            if (!isset($_ENV[$name])) $_ENV[$name] = $value;
        }
    }
}

$masterUrl = $_ENV['CONFIG_MASTER_URL'] ?? null;
$token = $_ENV['CONFIG_SECRET_TOKEN'] ?? null;

if (!$masterUrl || !$token) {
    echo "⚠️  Remote configuration skipped. CONFIG_MASTER_URL or CONFIG_SECRET_TOKEN not set.\n";
    exit(0);
}

echo "📡 Fetching remote configuration from $masterUrl...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, rtrim($masterUrl, '/') . '/api/config-manager/export');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Config-Token: ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "❌ Error fetching configuration (HTTP $httpCode). Response: $response\n";
    exit(1);
}

$data = json_decode($response, true);
if (!isset($data['success']) || !$data['success']) {
    echo "❌ Error in response: " . ($data['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

$configDir = __DIR__ . '/../config/channels';
if (!is_dir($configDir)) {
    mkdir($configDir, 0755, true);
}

foreach ($data['configs'] as $filename => $content) {
    if (!$content) continue;
    
    $targetPath = str_contains($filename, 'instances_rules') 
        ? __DIR__ . '/../config/' . $filename 
        : $configDir . '/' . $filename;
        
    file_put_contents($targetPath, $content);
    echo "  ✅ Updated $filename\n";
}

echo "🎉 Remote configuration successfully applied.\n";
