<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

// Explicit source override for validating coordinated changes before a driver release.
// Composer manifests, vendor files and the production autoloader remain unchanged.
if ($source = getenv('MAILCHIMP_TEST_DRIVER_SOURCE')) {
    $file = realpath($source.'/src/Drivers/MailchimpDriver.php');
    if ($file === false || !is_file($file)) {
        throw new RuntimeException('MAILCHIMP_TEST_DRIVER_SOURCE must identify the Mailchimp driver checkout.');
    }
    require_once $file;
}

require __DIR__.'/bootstrap.php';
