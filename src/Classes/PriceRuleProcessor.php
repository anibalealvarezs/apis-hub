<?php

namespace Classes;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Entities\Analytics\Channeled\ChanneledPriceRule;

class PriceRuleProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return array
     * @throws Exception
     */
    public static function processPriceRules(
        ArrayCollection $channeledCollection,
        EntityManager $manager
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $manager->getConnection();

        $uPR = [];
        $uCPR = [];

        foreach ($channeledCollection as $cpr) {
            $chan = (string)$cpr->channel;
            $pId = (string)$cpr->platformId;

            $prKey = KeyGenerator::generatePriceRuleKey($pId);
            if (!isset($uPR[$prKey])) {
                $uPR[$prKey] = ['price_rule_id' => $pId];
            }

            $cprKey = KeyGenerator::generateChanneledPriceRuleKey($chan, $pId);
            if (!isset($uCPR[$cprKey])) {
                $uCPR[$cprKey] = [
                    'price_rule_id' => $pId,
                    'channel' => $chan,
                    'platform_id' => $pId,
                    'platform_created_at' => isset($cpr->platformCreatedAt) ? $cpr->platformCreatedAt : null,
                    'data' => is_object($cpr->data) ? clone $cpr->data : (object)($cpr->data ?? []),
                ];
            }
        }

        // PRICE RULES
        $priceRuleMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uPR)) {
            $prIds = array_column($uPR, 'price_rule_id');
            $priceRuleMap = self::fetchAndInsertEntities(
                $conn,
                'price_rules',
                'price_rule_id',
                $prIds,
                ['price_rule_id'],
                [$uPR, fn ($p) => [$p['price_rule_id']]],
                fn ($chunk) => "SELECT id, price_rule_id FROM price_rules WHERE price_rule_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getPriceRuleMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRICE RULES
        $cprMap = [];
        if (!empty($uCPR)) {
            $cprKeys = [];
            foreach ($uCPR as $cprRow) {
                $cprKeys[] = ['channel' => $cprRow['channel'], 'platform_id' => $cprRow['platform_id']];
            }

            $cprMap = self::fetchChanneledEntities(
                $conn,
                $cprKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_price_rules WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledPriceRuleMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];

            foreach ($uCPR as $k => $cprRow) {
                $prKey = KeyGenerator::generatePriceRuleKey($cprRow['price_rule_id']);
                if (!isset($priceRuleMap['map'][$prKey])) {
                    continue;
                }
                $prId = $priceRuleMap['map'][$prKey]['id'];

                $row = [
                    'price_rule_id' => $prId,
                    'channel' => $cprRow['channel'],
                    'platform_id' => $cprRow['platform_id'],
                    'platform_created_at' => $cprRow['platform_created_at'] instanceof \DateTime ? $cprRow['platform_created_at']->format('Y-m-d H:i:s') : $cprRow['platform_created_at'],
                    'data' => json_encode($cprRow['data'])
                ];

                if (isset($cprMap[$k])) {
                    $row['id'] = $cprMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_price_rules', ['price_rule_id', 'channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_price_rules', ['id', 'price_rule_id', 'channel', 'platform_id', 'platform_created_at', 'data'], ['price_rule_id', 'channel', 'platform_id', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            $cprMap = self::fetchChanneledEntities(
                $conn,
                $cprKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_price_rules WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledPriceRuleMap($manager, $sql, $params)
            );
        }

        // We return the database ID of ChanneledPriceRule so we can use it to attach discounts
        $channeledEntities = [];
        foreach ($uCPR as $k => $cprRow) {
            if (isset($cprMap[$k])) {
                $channeledEntities[$cprRow['platform_id']] = $cprMap[$k]['id'];
            }
        }

        return [
            'priceRules' => array_column($uPR, 'price_rule_id'),
            'channeledPriceRules' => array_column($uCPR, 'platform_id'),
            'channels' => array_unique(array_column($uCPR, 'channel')),
            'cprDbIds' => $channeledEntities,
        ];
    }

    /**
     * Process discounts for a specific channeled price rule
     *
     * @param ArrayCollection $channeledCollection
     * @param string $priceRulePlatformId
     * @param int $channeledPriceRuleDbId
     * @param EntityManager $manager
     * @return array
     * @throws Exception
     */
    public static function processDiscounts(
        ArrayCollection $channeledCollection,
        string $priceRulePlatformId,
        int $channeledPriceRuleDbId,
        EntityManager $manager
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $manager->getConnection();

        $uDisc = [];
        $uCDisc = [];

        foreach ($channeledCollection as $cd) {
            $chan = (string)$cd->channel;
            $code = (string)$cd->code;

            $dKey = KeyGenerator::generateDiscountKey($code);
            if (!isset($uDisc[$dKey])) {
                $uDisc[$dKey] = ['code' => $code];
            }

            $cdKey = KeyGenerator::generateChanneledDiscountKey($chan, $code);
            if (!isset($uCDisc[$cdKey])) {
                $uCDisc[$cdKey] = [
                    'code' => $code,
                    'channel' => $chan,
                    'platform_id' => (string)$cd->platformId,
                    'platform_created_at' => isset($cd->platformCreatedAt) ? $cd->platformCreatedAt : null,
                    'data' => is_object($cd->data) ? clone $cd->data : (object)($cd->data ?? []),
                ];
            }
        }

        // DISCOUNTS
        $discountMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uDisc)) {
            $codes = array_column($uDisc, 'code');
            $discountMap = self::fetchAndInsertEntities(
                $conn,
                'discounts',
                'code',
                $codes,
                ['code'],
                [$uDisc, fn ($d) => [$d['code']]],
                fn ($chunk) => "SELECT id, code FROM discounts WHERE code IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getDiscountMap($manager, $sql, $params)
            );
        }

        // CHANNELED DISCOUNTS
        $cdMap = [];
        if (!empty($uCDisc)) {
            $cdKeys = [];
            foreach ($uCDisc as $cd) {
                $cdKeys[] = ['channel' => $cd['channel'], 'code' => $cd['code']];
            }

            $cdMap = self::fetchChanneledEntities(
                $conn,
                $cdKeys,
                'code',
                fn ($chunk) => "SELECT id, channel, code, data FROM channeled_discounts WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND code = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledDiscountMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];

            foreach ($uCDisc as $k => $cdRow) {
                $dKey = KeyGenerator::generateDiscountKey($cdRow['code']);
                if (!isset($discountMap['map'][$dKey])) {
                    continue;
                }
                $dId = $discountMap['map'][$dKey]['id'];

                $row = [
                    'discount_id' => $dId,
                    'channeled_price_rule_id' => $channeledPriceRuleDbId,
                    'channel' => $cdRow['channel'],
                    'platform_id' => $cdRow['platform_id'],
                    'code' => $cdRow['code'],
                    'platform_created_at' => $cdRow['platform_created_at'] instanceof \DateTime ? $cdRow['platform_created_at']->format('Y-m-d H:i:s') : $cdRow['platform_created_at'],
                    'data' => json_encode($cdRow['data'])
                ];

                if (isset($cdMap[$k])) {
                    $row['id'] = $cdMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_discounts', ['discount_id', 'channeled_price_rule_id', 'channel', 'platform_id', 'code', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_discounts', ['id', 'discount_id', 'channeled_price_rule_id', 'channel', 'platform_id', 'code', 'platform_created_at', 'data'], ['discount_id', 'channeled_price_rule_id', 'channel', 'platform_id', 'code', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);
        }

        return [
            'discountCodes' => array_column($uDisc, 'code'),
            'channeledDiscountCodes' => array_column($uCDisc, 'code'),
        ];
    }

    private static function fetchAndInsertEntities(\Doctrine\DBAL\Connection $conn, string $table, string $lookupField, array $lookups, array $insertCols, array $dataSource, callable $sqlGenerator, callable $mapGenerator): array
    {
        $chunks = array_chunk($lookups, 1000);
        $map = ['map' => [], 'mapReverse' => []];

        foreach ($chunks as $chunk) {
            $sql = $sqlGenerator($chunk);
            $fetched = $mapGenerator($conn, $sql, $chunk);
            $map['map'] = array_merge($map['map'], $fetched['map']);
            $map['mapReverse'] = $map['mapReverse'] + $fetched['mapReverse'];
        }

        $dataArray = $dataSource[0];
        $mappingFn = $dataSource[1];
        $toInsert = [];

        foreach ($dataArray as $key => $item) {
            if (!isset($map['map'][$key])) {
                $toInsert[] = $mappingFn($item);
            }
        }

        if (!empty($toInsert)) {
            $insertChunks = array_chunk($toInsert, 1000);
            foreach ($insertChunks as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $params = array_merge($params, $row);
                }
                $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, ($table === 'price_rules' ? 'price_rule_id' : 'code'), count($chunk));
                $conn->executeStatement($sql, $params);
            }

            foreach ($chunks as $chunk) {
                $sql = $sqlGenerator($chunk);
                $fetched = $mapGenerator($conn, $sql, $chunk);
                $map['map'] = array_merge($map['map'], $fetched['map']);
                $map['mapReverse'] = $map['mapReverse'] + $fetched['mapReverse'];
            }
        }

        return $map;
    }

    private static function fetchChanneledEntities(\Doctrine\DBAL\Connection $conn, array $keys, string $idField, callable $sqlGenerator, callable $mapGenerator): array
    {
        $chunks = array_chunk($keys, 1000);
        $map = [];
        foreach ($chunks as $chunk) {
            $params = [];
            foreach ($chunk as $c) {
                $params[] = $c['channel'];
                $params[] = $c[$idField];
            }
            $sql = $sqlGenerator($chunk);
            $fetched = $mapGenerator($conn, $sql, $params);
            $map = array_merge($map, $fetched);
        }
        return $map;
    }

    private static function bulkInsert(\Doctrine\DBAL\Connection $conn, string $table, array $columns, array $rows, int $chunkSize = 500): void
    {
        if (empty($rows)) {
            return;
        }
        $chunks = array_chunk($rows, $chunkSize);
        $uniqueCols = ($table === 'channeled_price_rules' ? ['channel', 'platform_id'] : ['channel', 'code']);
        
        foreach ($chunks as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $col) {
                    $params[] = $row[$col];
                }
            }
            $sql = Helpers::buildInsertIgnoreSql($table, $columns, $uniqueCols, count($chunk));
            $conn->executeStatement($sql, $params);
        }
    }

    private static function bulkUpsert(\Doctrine\DBAL\Connection $conn, string $table, array $columns, array $updateMap, array $rows, int $chunkSize = 500): void
    {
        if (empty($rows)) {
            return;
        }
        $chunks = array_chunk($rows, $chunkSize);
        
        $updateCols = [];
        foreach ($updateMap as $key => $val) {
            $updateCols[] = is_int($key) ? $val : $key;
        }

        foreach ($chunks as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $col) {
                    $params[] = $row[$col] ?? null;
                }
            }
            $sql = Helpers::buildUpsertSql($table, $columns, $updateCols, 'id', count($chunk));
            $conn->executeStatement($sql, $params);
        }
    }
}
