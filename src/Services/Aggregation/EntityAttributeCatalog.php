<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class EntityAttributeCatalog
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function get(int $channel, array $request): array
    {
        $resolver = new EntityAttributeResolver($this->em);
        $catalog = $resolver->describe();
        $relation = $request['relation'] ?? null;
        if ($relation !== null) $catalog = array_filter($catalog, static fn ($item) => $item['relation'] === $relation);
        // Catalog-only calls are cheap and used by widget field discovery.
        if (empty($request['values'])) return $catalog;
        $accounts = array_values((array)($request['account'] ?? []));
        if (!$accounts || array_filter($accounts, static fn ($id) => !ctype_digit((string)$id))) {
            throw new InvalidArgumentException('Attribute options require internal account IDs');
        }
        $conn = $this->em->getConnection();
        foreach ($catalog as $path => &$item) {
            // Numbers/dates use typed boundary inputs. Identifiers are not facets.
            if (!in_array($item['type'], ['string', 'text', 'boolean'], true) || $item['field'] === 'id') continue;
            $attribute = $resolver->resolve($path);
            $qb = $conn->createQueryBuilder()->select('DISTINCT '.$attribute['expression'].' AS value')
                ->from('metric_configs', 'mc')->where('mc.channel = :channel')->setParameter('channel', $channel)
                ->andWhere('mc.channeled_account_id IN (:accounts)')
                ->setParameter('accounts', $accounts, \Doctrine\DBAL\ArrayParameterType::INTEGER);
            foreach ($attribute['joins'] as $join) $qb->leftJoin($join['source'], $join['table'], $join['alias'], $join['condition']);
            $qb->andWhere($attribute['expression'].' IS NOT NULL');
            $qb->orderBy($attribute['expression'])->setMaxResults(201);
            $values = $qb->executeQuery()->fetchFirstColumn();
            $item['truncated'] = count($values) > 200;
            $item['options'] = [];
            foreach (array_slice($values, 0, 200) as $value) {
                if ($item['type'] === 'boolean') {
                    $value = in_array($value, [true, 1, '1', 'true', 't'], true) ? '1' : '0';
                    $item['options'][$value] = $value === '1' ? 'True' : 'False';
                } else {
                    $item['options'][(string)$value] = (string)$value;
                }
            }
        }
        unset($item);
        return $catalog;
    }
}
