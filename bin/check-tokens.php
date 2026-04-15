<?php

// CLI debug script to view tokens independently

$localPathRelative = __DIR__ . '/../storage/tokens/google_tokens.json';
$envPath = $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH');

echo "Checking Google Tokens...\n";
echo "============================\n";

if ($envPath) {
    echo "Path requested via env: $envPath\n";
    if (file_exists($envPath)) {
        echo "Found at env path!\n";
        echo "Content:\n" . file_get_contents($envPath) . "\n";
    } else {
        echo "Not found at env path.\n";
    }
}

echo "Checking default path: $localPathRelative\n";
if (file_exists($localPathRelative)) {
    echo "Found at relative path!\n";
    echo "Content:\n" . file_get_contents($localPathRelative) . "\n";
} else {
    echo "Not found at relative path.\n";
}
