<?php

require_once __DIR__ . '/../vendor/autoload.php';
\Helpers\Helpers::getProjectConfig();

$chan = 'google_search_console';
$driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chan);

echo "Fetching GSC assets...\n";
try {
    $assets = $driver->fetchAvailableAssets(true); // true to throw on error
    print_r($assets);
} catch (\Exception $e) {
    echo "ERROR fetching assets: " . $e->getMessage() . "\n";
}
