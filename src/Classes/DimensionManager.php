<?php

namespace Classes;

use Entities\Analytics\Channeled\DimensionKey;
use Entities\Analytics\Channeled\DimensionSet;
use Entities\Analytics\Channeled\DimensionValue;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiDriverCore\Interfaces\DimensionManagerInterface;
use Doctrine\ORM\EntityManager;

class DimensionManager implements DimensionManagerInterface
{
    private array $keyCache = [];
    private array $valueCache = [];
    private array $setCache = [];

    public function __construct(private EntityManager $em)
    {
    }

    public function clearCaches(): void
    {
        $this->keyCache = [];
        $this->valueCache = [];
        $this->setCache = [];
    }

    /**
     * @param array $dimensions Array of ['dimensionKey' => '...', 'dimensionValue' => '...']
     * @return DimensionSet
     */
    public function resolveDimensionSet(array $dimensions): DimensionSet
    {
        $hash = KeyGenerator::generateDimensionsHash($dimensions);

        // 2. Check Cache
        if (isset($this->setCache[$hash])) {
            return $this->setCache[$hash];
        }

        // 3. Database Lookup
        $set = $this->em->getRepository(DimensionSet::class)->findOneBy(['hash' => $hash]);
        if ($set) {
            $this->setCache[$hash] = $set;
            return $set;
        }

        // 4. Create New Set
        $set = new DimensionSet();
        $set->setHash($hash);

        foreach ($dimensions as $d) {
            $key = $this->resolveKey($d['dimensionKey']);
            $value = $this->resolveValue($key, $d['dimensionValue'] ?? '');
            $set->addValue($value);
        }

        $this->em->persist($set);
        $this->em->flush($set);

        $this->setCache[$hash] = $set;
        return $set;
    }

    private function resolveKey(string $name): DimensionKey
    {
        if (isset($this->keyCache[$name])) {
            return $this->keyCache[$name];
        }

        $key = $this->em->getRepository(DimensionKey::class)->findOneBy(['name' => $name]);
        if (!$key) {
            $key = new DimensionKey();
            $key->setName($name);
            $this->em->persist($key);
            $this->em->flush($key);
        }

        $this->keyCache[$name] = $key;
        return $key;
    }

    private function resolveValue(DimensionKey $key, string $valueStr): DimensionValue
    {
        $cacheKey = $key->getId() . ":" . $valueStr;
        if (isset($this->valueCache[$cacheKey])) {
            return $this->valueCache[$cacheKey];
        }

        $value = $this->em->getRepository(DimensionValue::class)->findOneBy([
            'dimensionKey' => $key,
            'value' => $valueStr
        ]);

        if (!$value) {
            $value = new DimensionValue();
            $value->setDimensionKey($key);
            $value->setValue($valueStr);
            $this->em->persist($value);
            $this->em->flush($value);
        }

        $this->valueCache[$cacheKey] = $value;
        return $value;
    }
}
