<?php
// cli-config.php
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Helpers\Helpers;
use Symfony\Component\Console\Helper\HelperSet;

return new HelperSet(['em' => new EntityManagerHelper(Helpers::getManager())]);
