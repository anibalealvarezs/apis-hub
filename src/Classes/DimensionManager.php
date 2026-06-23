<?php

namespace Classes;

use Entities\Analytics\Channeled\DimensionKey;
use Entities\Analytics\Channeled\DimensionSet;
use Entities\Analytics\Channeled\DimensionValue;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiDriverCore\Interfaces\DimensionManagerInterface;
use Doctrine\ORM\EntityManagerInterface;

class DimensionManager implements DimensionManagerInterface
{
    private array $keyCache = [];
    private array $valueCache = [];
    private array $setCache = [];

    /** @var EntityManagerInterface */
    private $em;

    public function __construct($em)
    {
        $this->em = $em;
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

        // 4. Create New Set via Atomic Upsert to prevent Race Conditions
        $conn = $this->em->getConnection();
        $affectedRows = $conn->executeStatement(
            'INSERT INTO dimension_sets (hash) VALUES (?) ON CONFLICT (hash) DO NOTHING',
            [$hash]
        );
        
        $row = $conn->fetchAssociative('SELECT id FROM dimension_sets WHERE hash = ?', [$hash]);
        $setId = (int)$row['id'];

        if ($affectedRows > 0) {
            // We just inserted the set, so we must insert the relationships
            foreach ($dimensions as $d) {
                $key = $this->resolveKey($d['dimensionKey']);
                $value = $this->resolveValue($key, $d['dimensionValue'] ?? '');
                
                $conn->executeStatement(
                    'INSERT INTO dimension_set_items (dimension_set_id, dimension_value_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                    [$setId, $value->getId()]
                );
            }
        }

        /** @var DimensionSet $set */
        $set = $this->em->getReference(DimensionSet::class, $setId);
        $this->setCache[$hash] = $set;
        
        return $set;
    }

    private function resolveKey(string $name): DimensionKey
    {
        if (isset($this->keyCache[$name])) {
            return $this->keyCache[$name];
        }

        // Atomic upsert: INSERT ... ON CONFLICT DO NOTHING, then SELECT.
        // Avoids the check-then-insert race condition that causes unique violations
        // when multiple workers process the same dimension key simultaneously.
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO dimension_keys (name) VALUES (?) ON CONFLICT (name) DO NOTHING',
            [$name]
        );
        $row = $conn->fetchAssociative('SELECT id, name FROM dimension_keys WHERE name = ?', [$name]);

        /** @var DimensionKey $key */
        $key = $this->em->getReference(DimensionKey::class, (int)$row['id']);
        $this->keyCache[$name] = $key;
        return $key;
    }

    private function resolveValue(DimensionKey $key, string $valueStr): DimensionValue
    {
        $cacheKey = $key->getId() . ":" . $valueStr;
        if (isset($this->valueCache[$cacheKey])) {
            return $this->valueCache[$cacheKey];
        }

        // Same atomic upsert pattern for values.
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO dimension_values (dimension_key_id, value) VALUES (?, ?) ON CONFLICT (dimension_key_id, value) DO NOTHING',
            [$key->getId(), $valueStr]
        );
        $row = $conn->fetchAssociative(
            'SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?',
            [$key->getId(), $valueStr]
        );

        /** @var DimensionValue $value */
        $value = $this->em->getReference(DimensionValue::class, (int)$row['id']);
        $this->valueCache[$cacheKey] = $value;
        return $value;
    }
}
