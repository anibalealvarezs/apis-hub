<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\MetricConfig;
use InvalidArgumentException;

/** Resolves only mapped, owning to-one relationships; never accepts SQL identifiers from clients. */
final class EntityAttributeResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function resolve(string $path, string $root = 'mc'): array
    {
        $path = preg_replace('/__\d+$/', '', $path);
        $parts = explode('.', $path);
        if (array_shift($parts) !== 'attributes' || count($parts) < 2 || count($parts) > 5) {
            throw new InvalidArgumentException('Expected attributes.<relationship>.<field>');
        }
        $metadata = $this->em->getClassMetadata(MetricConfig::class);
        $source = $root;
        $joins = [];
        $traversed = [];
        $field = array_pop($parts);
        foreach ($parts as $relation) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $relation) || !$metadata->hasAssociation($relation)
                || !$metadata->isSingleValuedAssociation($relation)) {
                throw new InvalidArgumentException('Unknown or collection entity relationship: '.$relation);
            }
            $association = $metadata->getAssociationMapping($relation);
            if (!$association->isOwningSide() || count($association->joinColumns) !== 1) {
                throw new InvalidArgumentException('Attribute filters require a single owning join column');
            }
            $target = $this->em->getClassMetadata($association->targetEntity);
            $traversed[] = $relation;
            $alias = 'attr_'.substr(sha1(implode('.', $traversed)), 0, 12);
            $join = $association->joinColumns[0];
            $joins[] = ['source' => $source, 'table' => $target->getTableName(), 'alias' => $alias,
                'condition' => "$source.{$join->name} = $alias.{$join->referencedColumnName}"];
            $source = $alias;
            $metadata = $target;
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $field) || !$metadata->hasField($field)) {
            throw new InvalidArgumentException('Unknown entity attribute: '.$field);
        }
        $mapping = $metadata->getFieldMapping($field);
        if (in_array($mapping->type, ['json', 'json_array', 'blob', 'binary', 'object'], true)) {
            throw new InvalidArgumentException('Filter a mapped scalar entity attribute');
        }
        return ['expression' => $source.'.'.$metadata->getColumnName($field), 'joins' => $joins,
            'type' => $mapping->type, 'field' => $field];
    }

    public function describe(): array
    {
        $root = $this->em->getClassMetadata(MetricConfig::class);
        $result = [];
        foreach ($root->getAssociationNames() as $relation) {
            if (!$root->isSingleValuedAssociation($relation)) continue;
            $association = $root->getAssociationMapping($relation);
            if (!$association->isOwningSide()) continue;
            $target = $this->em->getClassMetadata($association->targetEntity);
            foreach ($target->getFieldNames() as $field) {
                $mapping = $target->getFieldMapping($field);
                if (in_array($mapping->type, ['json', 'json_array', 'blob', 'binary', 'object'], true)) continue;
                $path = 'attributes.'.$relation.'.'.$field;
                $options = [];
                if ($mapping->enumType && enum_exists($mapping->enumType)) {
                    foreach (($mapping->enumType)::cases() as $case) {
                        $options[(string)$case->value] = $case->name;
                    }
                }
                $result[$path] = ['relation' => $relation, 'field' => $field, 'type' => $mapping->type,
                    'label' => trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $relation.' / '.$field)), 'options' => $options];
            }
        }
        return $result;
    }
}
