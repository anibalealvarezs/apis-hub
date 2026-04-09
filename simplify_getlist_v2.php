<?php

$dir = 'D:\laragon\www\apis-hub\src\Classes\Requests\\';
$files = glob($dir . '*Requests.php');

foreach ($files as $file) {
    if (!is_file($file)) continue;
    
    $content = file_get_contents($file);
    
    // Look for the if block
    $lines = explode("\n", $content);
    $newLines = [];
    $skipping = false;
    $found = false;
    
    foreach ($lines as $line) {
        if (preg_match('/^\s*if \(\$chanEnum === Channel::facebook_(?:marketing|organic|shopify|google_search_console)\) \{/', $line)) {
            $skipping = true;
            $found = true;
            continue;
        }
        
        if ($skipping && preg_match('/^\s*return (.*);/', $line, $matches)) {
            $newLines[] = "        return " . $matches[1] . ";";
            continue;
        }
        
        if ($skipping && preg_match('/^\s*\}/', $line)) {
            $skipping = false;
            continue;
        }

        if (preg_match('/^\s*throw new (?:\\\\)?Exception\("Channel .*" not supported for .* entities"\);/', $line)) {
             continue;
        }

        if (!$skipping) {
            $newLines[] = $line;
        }
    }
    
    if ($found) {
        file_put_contents($file, implode("\n", $newLines));
        echo "Simplified " . basename($file) . "\n";
    }
}
