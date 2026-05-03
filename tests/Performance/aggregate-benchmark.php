<?php

    declare(strict_types=1);

    use Tests\Performance\AggregateBenchmarkRunner;

    require_once __DIR__.'/../bootstrap.php';
    require_once __DIR__.'/AggregateBenchmarkRunner.php';

    exit(AggregateBenchmarkRunner::main($argv));

