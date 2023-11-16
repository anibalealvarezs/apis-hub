<?php

namespace Fixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Entities\Entity;
use Helpers\Helpers;
use Interfaces\DataLoaderInterface;

abstract class DataLoaderFixture extends AbstractFixture implements FixtureInterface, DataLoaderInterface
{
    /**
     * @param ObjectManager $manager
     * @param string $reference
     * @param int $count
     * @param callable $factory
     * @param array $args
     * @param array $references
     */
    public function createMany(ObjectManager $manager, string $reference, int $count, callable $factory, array $args = [], array $references = []): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $entity = $this->argumentedCallable($factory, $args);
            $manager->persist($entity);
            $manager->flush();
            $this->addReference($reference.'-'.$i, $entity);
        }
    }

    /**
     * @param callable $callable
     * @param array $args
     * @return mixed
     */
    protected function argumentedCallable(callable $callable, array $args = []): mixed
    {
        return call_user_func_array($callable, $args);
    }

    /**
     * @param Entity $entity
     * @param $method
     * @param array $references
     * @return object
     */
    public static function setRelations(Entity $entity, $method, array $references = [])
    {
        $refNumbers = Helpers::getNumbersArray(count($references));
        shuffle($refNumbers);
        $qty = rand(1, count($references));
        for ($i = 0; $i < $qty; $i++) {
            $entity->{$method}($references[$refNumbers[$i]]);
        }
        return $entity;
    }

    /**
     * @param string $key
     * @param int $qty
     * @return array
     */
    protected function getReferenceList(string $key, int $qty): array
    {
        $array = [];
        for ($i = 1; $i <= $qty; $i++) {
            $array[$i] = $this->getReference($key . '-' . $i);
        }
        return $array;
    }
}
