<?php

declare(strict_types=1);

namespace Services\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/** Commerce reads are scoped through the persisted store-to-audience relationship. */
final class CatalogCommerceReadService
{
    public const TYPES = [
        'stores' => ['channeled_stores', 'Stores'],
        'orders' => ['channeled_orders', 'Orders'],
        'products' => ['channeled_products', 'Products'],
        'variants' => ['channeled_product_variants', 'Product Variants'],
        'promotions' => ['channeled_price_rules', 'Promotions'],
        'carts' => ['channeled_carts', 'Carts'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function capabilities(): array
    {
        $schema = $this->connection->createSchemaManager();
        $storeReady = $schema->tablesExist(['channeled_stores'])
            && isset($schema->listTableColumns('channeled_stores')['channeled_account_id']);
        $entities = [];
        foreach (self::TYPES as $type => [$table, $label]) {
            $ready = $storeReady && $schema->tablesExist([$table]);
            if ($ready && $type === 'variants') {
                $ready = !empty($entities['products']['supported']);
            }
            if ($ready && !in_array($type, ['stores', 'variants'], true)) {
                $ready = isset($schema->listTableColumns($table)['channeled_store_id']);
            }
            $entities[$type] = [
                'entity_type' => $type, 'label' => $label, 'family' => 'commerce', 'phase' => 4,
                'supported' => $ready, 'channels' => ['mailchimp'],
                'filters' => ['channel' => ['mailchimp'], 'parent_reference' => ['accounts', 'stores']],
                'sortable' => ['name', 'platform_id', 'platform_created_at', 'updated_at'],
                'default_sort' => ['field' => 'updated_at', 'dir' => 'DESC'],
                'metrics' => [], 'activity_coverage' => 'lifecycle_timestamps',
            ];
        }
        return $entities;
    }

    public function list(string $type, array $payload, array $accounts): array
    {
        [$from, $where, $params, $types] = $this->queryScope($type, $payload, $accounts);
        $page = max(1, (int)($payload['pagination']['page'] ?? $payload['page'] ?? 1));
        $perPage = max(1, min(100, (int)($payload['pagination']['per_page'] ?? $payload['per_page'] ?? 25)));
        $sorts = ['name' => $type === 'stores' ? 'e.name' : 'e.platform_id',
            'platform_id' => 'e.platform_id', 'platform_created_at' => 'e.platform_created_at', 'updated_at' => 'e.updated_at'];
        $field = $payload['sort']['field'] ?? 'updated_at';
        $sort = $sorts[$field] ?? 'e.updated_at';
        $dir = strtoupper((string)($payload['sort']['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $total = (int)$this->connection->fetchOne("SELECT COUNT(e.id) $from WHERE $where", $params, $types);
        $select = $this->selectColumns();
        $rows = $this->connection->fetchAllAssociative(
            "SELECT $select $from WHERE $where ORDER BY $sort $dir, e.id ASC LIMIT $perPage OFFSET ".(($page - 1) * $perPage),
            $params, $types
        );
        return [
            'data' => array_map(fn ($row) => $this->hydrate($type, $row), $rows),
            'meta' => [
                'total' => $total, 'page' => $page, 'per_page' => $perPage,
                'last_page' => max(1, (int)ceil($total / $perPage)), 'sort' => $field, 'dir' => $dir,
                'scope_summary' => ['authorized_channels' => ['mailchimp'], 'account_counts' => ['mailchimp' => count($accounts['mailchimp'] ?? [])]],
            ],
        ];
    }

    public function profile(string $type, CatalogEntityReference $reference, array $payload, array $accounts): ?array
    {
        if ($reference->getLayer() !== 'channeled' || $reference->getChannel() !== 'mailchimp') return null;
        [$from, $where, $params, $types] = $this->queryScope($type, $payload, $accounts);
        $params['id'] = $reference->getId();
        $row = $this->connection->fetchAssociative(
            'SELECT '.$this->selectColumns()." $from WHERE $where AND e.id = :id", $params, $types
        );
        if (!$row) return null;
        $record = $this->hydrate($type, $row);
        $children = [];
        if ($type === 'stores') {
            $capabilities = $this->capabilities();
            foreach (['orders', 'products', 'promotions', 'carts'] as $child) {
                if (empty($capabilities[$child]['supported'])) continue;
                $selection = $payload;
                $selection['filters']['parent_reference'] = $record['reference'];
                $selection['pagination'] = ['page' => 1, 'per_page' => 5];
                $result = $this->list($child, $selection, $accounts);
                $children[$child] = [
                    'entity_type' => $child, 'label' => self::TYPES[$child][1],
                    'total_count' => $result['meta']['total'], 'items' => $result['data'],
                ];
            }
        }
        return [
            'record' => $record, 'source_records' => [[
                'layer' => 'channeled', 'table' => self::TYPES[$type][0], 'id' => $record['id'],
                'platform_id' => $record['platform_id'], 'channel' => 'mailchimp', 'name' => $record['name'],
            ]],
            'relationships' => ['parents' => $record['relationships'], 'children' => $children],
            'metrics' => ['summary' => [], 'series' => [], 'coverage' => ['notice' => 'Commerce performance is available in Connected Stores. Entity measures are delivered separately.']],
        ];
    }

    private function selectColumns(): string
    {
        return 'e.*, s.id AS store_record_id, s.name AS store_name, s.platform_id AS store_platform_id,
            ca.id AS owning_account_id, ca.name AS account_name, ca.platform_id AS account_platform_id';
    }

    private function queryScope(string $type, array $payload, array $accounts): array
    {
        $table = self::TYPES[$type][0] ?? throw new InvalidArgumentException('Unsupported commerce entity.');
        $from = "FROM $table e ";
        $from .= match ($type) {
            'stores' => 'JOIN channeled_stores s ON s.id = e.id ',
            'variants' => 'JOIN channeled_products p ON p.id = e.channeled_product_id JOIN channeled_stores s ON s.id = p.channeled_store_id ',
            default => 'JOIN channeled_stores s ON s.id = e.channeled_store_id ',
        };
        $from .= 'JOIN channeled_accounts ca ON ca.id = s.channeled_account_id JOIN channels ch ON ch.id = ca.channel';
        $params = ['accounts' => array_map(static fn ($row) => (int)$row['id'], $accounts['mailchimp'] ?? [])];
        $types = ['accounts' => ArrayParameterType::INTEGER];
        $where = "ch.name = 'mailchimp' AND e.channel = s.channel AND ca.channel = s.channel AND ca.id IN (:accounts)";
        $filters = $payload['filters'] ?? [];
        if (isset($filters['channel']) && $filters['channel'] !== '' && $filters['channel'] !== 'mailchimp') $where .= ' AND 1 = 0';
        if (!empty($filters['account_id'])) {
            $where .= ' AND ca.id = :account';
            $params['account'] = (int)$filters['account_id'];
        }
        if (!empty($filters['parent_reference'])) {
            $parent = CatalogEntityReference::parse($filters['parent_reference']);
            if ($parent->getChannel() !== 'mailchimp' || $parent->getLayer() !== 'channeled') $where .= ' AND 1 = 0';
            $column = match ($parent->getEntityType()) {
                'stores' => 's.id', 'accounts' => 'ca.id',
                default => throw new InvalidArgumentException('Unsupported commerce parent.'),
            };
            $where .= " AND $column = :parent";
            $params['parent'] = $parent->getId();
        }
        $search = trim((string)($payload['search'] ?? ''));
        if ($search !== '') {
            $field = $type === 'stores' ? 'e.name' : "COALESCE(".$this->jsonText('title').", ".$this->jsonText('source_order_id').", ".$this->jsonText('source_id').", e.platform_id)";
            $where .= " AND (LOWER(COALESCE($field, '')) LIKE :search OR LOWER(e.platform_id) LIKE :search)";
            $params['search'] = '%'.strtolower($search).'%';
        }
        return [$from, $where, $params, $types];
    }

    private function jsonText(string $field): string
    {
        return $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform
            ? "JSON_UNQUOTE(JSON_EXTRACT(e.data, '$.$field'))"
            : "e.data->>'$field'";
    }

    private function hydrate(string $type, array $row): array
    {
        $data = is_array($row['data'] ?? null) ? $row['data'] : json_decode($row['data'] ?? '{}', true);
        $data = is_array($data) ? $data : [];
        $id = (int)$row['id'];
        $attributes = array_intersect_key($data, array_flip([
            'title', 'domain', 'platform', 'source_id', 'source_order_id', 'source_store_id',
            'currency_code', 'currency', 'total_amount', 'order_total', 'financial_status',
            'fulfillment_status', 'processed_at_foreign', 'cancelled_at_foreign', 'updated_at_foreign',
            'sku', 'price', 'inventory_quantity', 'enabled', 'starts_at', 'ends_at', 'amount', 'type', 'target',
        ]));
        $name = $row['name'] ?? $data['title'] ?? $data['source_order_id'] ?? $data['source_id'] ?? $row['platform_id'];
        $parents = [
            'account' => [
                'reference' => CatalogEntityReference::format('channeled', 'mailchimp', 'accounts', $row['owning_account_id']),
                'entity_type' => 'accounts', 'id' => (int)$row['owning_account_id'],
                'name' => $row['account_name'], 'platform_id' => $row['account_platform_id'],
            ],
        ];
        if ($type !== 'stores') {
            $parents['store'] = [
                'reference' => CatalogEntityReference::format('channeled', 'mailchimp', 'stores', $row['store_record_id']),
                'entity_type' => 'stores', 'id' => (int)$row['store_record_id'],
                'name' => $row['store_name'], 'platform_id' => $row['store_platform_id'],
            ];
        }
        return [
            'reference' => CatalogEntityReference::format('channeled', 'mailchimp', $type, $id),
            'entity_type' => $type, 'id' => $id, 'name' => (string)$name, 'platform_id' => (string)$row['platform_id'],
            'channel' => 'mailchimp', 'channel_label' => 'Mailchimp', 'status' => $data['financial_status'] ?? null,
            'owning_account_id' => (int)$row['owning_account_id'],
            'attributes' => $attributes, 'relationships' => $parents,
            'provenance' => [
                'layer' => 'channeled', 'table' => self::TYPES[$type][0],
                'platform_created_at' => $row['platform_created_at'] ?? null,
                'first_synced_at' => $row['created_at'] ?? null, 'last_synced_at' => $row['updated_at'] ?? null,
                'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
            ],
            'time_semantics' => ['Current source snapshot. Source timestamps describe observed lifecycle milestones.'],
        ];
    }
}
