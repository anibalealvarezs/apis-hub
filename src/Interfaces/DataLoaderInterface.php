<?php

namespace Interfaces;

use Doctrine\Persistence\ObjectManager;

interface DataLoaderInterface
{
    /**
     * @param array $references
     * @return object
     */
    public static function getNewEntity(array $references = []): object;

    /**
     * @param ObjectManager $manager
     * @param string $reference
     * @param int $count
     * @param callable $factory
     */
    public function createMany(ObjectManager $manager, string $reference, int $count, callable $factory): void;
}