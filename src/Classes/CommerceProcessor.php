<?php

declare(strict_types=1);

namespace Classes;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channel;
use Helpers\Helpers;
use RuntimeException;

/** Persist normalized commerce records without provider-specific field rules. */
final class CommerceProcessor
{
    public static function process(string $type, ArrayCollection $records, EntityManager $manager): void
    {
        $connection = $manager->getConnection();
        $connection->transactional(function () use ($type, $records, $manager, $connection): void {
            $normalized = new ArrayCollection();
            $channels = [];
            foreach ($records as $record) {
                $channel = (string)$record->channel;
                if (!isset($channels[$channel])) {
                    $channels[$channel] = ctype_digit($channel) ? (int)$channel
                        : $manager->getRepository(Channel::class)->findOneBy(['name' => $channel])?->getId();
                    if (!$channels[$channel]) throw new RuntimeException('Unknown commerce channel.');
                }
                $copy = clone $record;
                $copy->setChannel($channels[$channel]);
                $normalized->add($copy);
            }
            if ($type === 'customer') {
                CustomerProcessor::processCustomers($normalized, $manager);
                self::linkStores($connection, 'channeled_customers', $normalized);
                return;
            }
            if ($type === 'product') {
                ProductProcessor::processProducts($normalized, $manager);
                self::linkStores($connection, 'channeled_products', $normalized);
                return;
            }
            if ($type === 'order') {
                foreach ($normalized as $record) {
                    $storeId = self::storeId($connection, $record);
                    if (!$storeId) continue;
                    $data = (array)($record->data ?? []);
                    // A supplied source collection is authoritative for this snapshot.
                    // Clear old pivots inside the transaction before rebuilding relationships.
                    foreach ([['promos', 'channeled_order_channeled_discounts'], ['lines', 'channeled_order_channeled_products'],
                        ['lines', 'channeled_order_channeled_product_variants']] as [$field, $table]) {
                        if (!isset($data[$field]) || !is_array($data[$field])) continue;
                        $connection->executeStatement(
                            "DELETE FROM $table WHERE channeled_order_id IN (SELECT id FROM channeled_orders WHERE channel = ? AND platform_id = ?)",
                            [(int)$record->channel, (string)$record->platformId]
                        );
                    }
                    $codes = [];
                    foreach ($data['promos'] ?? [] as $promotion) {
                        $code = $promotion['code'] ?? null;
                        if (!is_string($code) || $code === '') continue;
                        $expression = $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform
                            ? "JSON_UNQUOTE(JSON_EXTRACT(d.data, '$.code'))" : "d.data->>'code'";
                        $matches = $connection->fetchFirstColumn(
                            "SELECT d.code FROM channeled_discounts d JOIN channeled_price_rules r ON r.id = d.channeled_price_rule_id
                             WHERE r.channeled_store_id = ? AND d.channel = ? AND d.platform_id = d.code AND $expression = ?",
                            [$storeId, (int)$record->channel, $code]
                        );
                        if (count($matches) === 1) $codes[] = $matches[0];
                    }
                    $record->discountCodes = array_values(array_unique($codes));
                }
                OrderProcessor::processOrders($normalized, $manager);
                foreach ($normalized as $record) {
                    $storeId = self::storeId($connection, $record);
                    if (!$storeId) continue;
                    $accountId = $connection->fetchOne('SELECT channeled_account_id FROM channeled_stores WHERE id = ?', [$storeId]);
                    $connection->executeStatement(
                        'UPDATE channeled_customers SET channeled_store_id = ?, channeled_account_id = ?
                         WHERE id IN (SELECT channeled_customer_id FROM channeled_orders WHERE channel = ? AND platform_id = ?)',
                        [$storeId, $accountId, (int)$record->channel, (string)$record->platformId]
                    );
                    $connection->executeStatement(
                        'UPDATE channeled_products SET channeled_store_id = ? WHERE channel = ? AND id IN
                         (SELECT link.channeled_product_id FROM channeled_order_channeled_products link
                          JOIN channeled_orders source ON source.id = link.channeled_order_id
                          WHERE source.channel = ? AND source.platform_id = ?)',
                        [$storeId, (int)$record->channel, (int)$record->channel, (string)$record->platformId]
                    );
                }
                return;
            }
            foreach ($normalized as $record) {
                $data = (array)($record->data ?? []);
                $channel = (int)$record->channel;
                $platformId = (string)$record->platformId;
                $date = $record->platformCreatedAt ?? null;
                $base = [
                    'channel' => $channel, 'platform_id' => $platformId,
                    'platform_created_at' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : $date,
                    'data' => json_encode($data, JSON_THROW_ON_ERROR),
                ];
                if ($type === 'account') {
                    $accountName = (string)($data['account_id'] ?? '');
                    if ($accountName === '') throw new RuntimeException('Account identity is required.');
                    if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                        $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['commerce-account:'.$accountName]);
                    }
                    $accountId = (int)$connection->fetchOne('SELECT id FROM accounts WHERE name = ? ORDER BY id LIMIT 1', [$accountName]);
                    if (!$accountId) {
                        $connection->insert('accounts', [
                            'name' => $accountName, 'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'), 'version' => 1,
                        ]);
                        $accountId = (int)$connection->lastInsertId();
                    }
                    $existing = $connection->fetchOne('SELECT account_id FROM channeled_accounts WHERE channel = ? AND platform_id = ?', [$channel, $platformId]);
                    if ($existing && (int)$existing !== $accountId) throw new RuntimeException('Source account identity collision.');
                    self::upsert($connection, 'channeled_accounts', $base + [
                        'account_id' => $accountId, 'name' => (string)($record->title ?? $data['name'] ?? $platformId),
                        'type' => 'audience', 'enabled' => 1,
                    ], ['platform_id', 'channel'], ['enabled']);
                    continue;
                }
                if ($type === 'store') {
                    $audience = $connection->fetchAssociative(
                        'SELECT ca.id, ca.account_id FROM channeled_accounts ca JOIN accounts a ON a.id = ca.account_id
                         WHERE ca.channel = ? AND ca.platform_id = ? AND a.name = ?',
                        [$channel, (string)($data['audience_id'] ?? ''), (string)($data['account_id'] ?? '')]
                    );
                    if (!$audience) throw new RuntimeException('Store audience must be synchronized before its store.');
                    $canonical = $channel.':'.$platformId;
                    $metadata = [
                        'name' => $data['name'] ?? null, 'domain' => $data['domain'] ?? null,
                        'currency_code' => $data['currency_code'] ?? null,
                    ];
                    self::upsert($connection, 'stores', ['canonical_id' => $canonical] + $metadata + ['data' => $base['data']], ['canonical_id']);
                    $storeId = (int)$connection->fetchOne('SELECT id FROM stores WHERE canonical_id = ?', [$canonical]);
                    self::upsert($connection, 'channeled_stores', $base + $metadata + [
                        'store_id' => $storeId, 'account_id' => (int)$audience['account_id'],
                        'channeled_account_id' => (int)$audience['id'],
                    ], ['platform_id', 'channel']);
                    // Repair late store discovery for already cached orders.
                    self::linkStores($connection, 'channeled_orders', new ArrayCollection([$record]), true);
                    continue;
                }
                $storeId = self::storeId($connection, $record);
                if ($type === 'cart') {
                    self::upsert($connection, 'channeled_carts', $base + ['channeled_store_id' => $storeId], ['platform_id', 'channel']);
                } elseif ($type === 'price_rule') {
                    self::upsert($connection, 'price_rules', ['price_rule_id' => $platformId], ['price_rule_id']);
                    $ruleId = (int)$connection->fetchOne('SELECT id FROM price_rules WHERE price_rule_id = ?', [$platformId]);
                    self::upsert($connection, 'channeled_price_rules', $base + [
                        'price_rule_id' => $ruleId, 'channeled_store_id' => $storeId,
                    ], ['platform_id', 'channel']);
                    $channeledRuleId = (int)$connection->fetchOne(
                        'SELECT id FROM channeled_price_rules WHERE channel = ? AND platform_id = ?', [$channel, $platformId]
                    );
                    foreach ($data['codes'] ?? [] as $code) {
                        if (empty($code['identity']) || empty($code['code'])) continue;
                        self::upsert($connection, 'discounts', ['code' => $code['identity']], ['code']);
                        $discountId = (int)$connection->fetchOne('SELECT id FROM discounts WHERE code = ?', [$code['identity']]);
                        self::upsert($connection, 'channeled_discounts', [
                            'channel' => $channel, 'platform_id' => $code['identity'], 'code' => $code['identity'],
                            'discount_id' => $discountId, 'channeled_price_rule_id' => $channeledRuleId,
                            'platform_created_at' => $code['created_at_foreign'] ?? null,
                            'data' => json_encode($code, JSON_THROW_ON_ERROR),
                        ], ['code', 'channel']);
                    }
                } else {
                    throw new RuntimeException('Unsupported commerce record type.');
                }
            }
        });
    }

    public static function storeId(Connection $connection, object $record): ?int
    {
        $data = (array)($record->data ?? []);
        $platformId = $record->storePlatformId ?? $data['store_platform_id'] ?? null;
        if (!$platformId) return null;
        $id = $connection->fetchOne('SELECT id FROM channeled_stores WHERE channel = ? AND platform_id = ?', [
            (int)$record->channel, $platformId,
        ]);
        return $id ? (int)$id : null;
    }

    private static function linkStores(Connection $connection, string $table, ArrayCollection $records, bool $lateStore = false): void
    {
        foreach ($records as $record) {
            $data = (array)($record->data ?? []);
            $id = self::storeId($connection, $record);
            $accountId = $id ? $connection->fetchOne('SELECT channeled_account_id FROM channeled_stores WHERE id = ?', [$id]) : null;
            if ($table === 'channeled_customers' && !$accountId) {
                $context = $record->getContext();
                $account = $context['channeledAccount'] ?? null;
                if (is_object($account) && method_exists($account, 'getPlatformId')) {
                    $accountId = $connection->fetchOne(
                        'SELECT id FROM channeled_accounts WHERE channel = ? AND platform_id = ?',
                        [(int)$record->channel, (string)$account->getPlatformId()]
                    );
                }
            }
            if (!$id && !$accountId) continue;
            if ($lateStore) {
                $jsonField = Helpers::isPostgres() ? "data->>'store_platform_id'" : "json_extract(data, '$.store_platform_id')";
                $connection->executeStatement("UPDATE $table SET channeled_store_id = ? WHERE channel = ? AND $jsonField = ?", [
                    $id, (int)$record->channel, $data['store_platform_id'],
                ]);
            } else {
                $changes = [];
                if ($id) $changes['channeled_store_id'] = $id;
                if ($table === 'channeled_customers' && $accountId) $changes['channeled_account_id'] = (int)$accountId;
                $connection->update($table, $changes, [
                    'channel' => (int)$record->channel, 'platform_id' => (string)$record->platformId,
                ]);
            }
        }
    }

    private static function upsert(Connection $connection, string $table, array $values, array $unique, array $preserve = []): void
    {
        $now = date('Y-m-d H:i:s');
        $updates = array_values(array_diff(array_keys($values), $unique, $preserve));
        $values += ['created_at' => $now, 'updated_at' => $now, 'version' => 1];
        $updates[] = 'updated_at';
        $sql = Helpers::buildUpsertSql($table, array_keys($values), $updates, $unique);
        $connection->executeStatement($sql, array_values($values));
    }
}
