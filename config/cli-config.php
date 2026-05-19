<?php

// cli-config.php
use Helpers\Helpers;
use Symfony\Component\Console\Helper\HelperSet;

$entityManagerHelperClass = 'Doctrine\\ORM\\Tools\\Console\\Helper\\EntityManagerHelper';

if (class_exists($entityManagerHelperClass)) {
	$emHelper = new $entityManagerHelperClass(Helpers::getManager());

	return new HelperSet(['em' => $emHelper]);
}

// Doctrine ORM >=3 path: keep returning a HelperSet object for legacy callers.
return new HelperSet([]);
