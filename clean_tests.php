<?php

$dir = 'D:\laragon\www\apis-hub\tests\Unit\Classes\Requests\\';
$files = glob($dir . '*Test.php');

foreach ($files as $file) {
    if (!is_file($file)) continue;
    
    $content = file_get_contents($file);
    
    // Remove individual testSupportedChannels method
    $pattern = '/\s+public function testSupportedChannels\(\): void\s+\{[^}]+\}/s';
    $newContent = preg_replace($pattern, '', $content);
    
    if ($newContent !== $content) {
        file_put_contents($file, $newContent);
        echo "Removed testSupportedChannels from " . basename($file) . "\n";
    }
}
