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
                $uPR[$prKey] = ['priceRuleId' => $pId];
            }

            $cprKey = KeyGenerator::generateChanneledPriceRuleKey($chan, $pId);
            if (!isset($uCPR[$cprKey])) {
                $uCPR[$cprKey] = [
                    'priceRuleId' => $pId,
                    'channel' => $chan,
                    'platformId' => $pId,
                    'platformCreatedAt' => isset($cpr->platformCreatedAt) ? $cpr->platformCreatedAt : null,
                    'data' => is_object($cpr->data) ? clone $cpr->data : (object)($cpr->data ?? []),
                ];
            }
        }

        // PRICE RULES
        $priceRuleMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uPR)) {
            $prIds = array_column($uPR, 'priceRuleId');
            $priceRuleMap = self::fetchAndInsertEntities(
                $conn,
                'price_rules',
                'priceRuleId',
                $prIds,
                ['priceRuleId'],
                [$uPR, fn ($p) => [$p['priceRuleId']]],
                fn ($chunk) => "SELECT id, priceRuleId FROM price_rules WHERE priceRuleId IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getPriceRuleMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRICE RULES
        $cprMap = [];
        if (!empty($uCPR)) {
            $cprKeys = [];
            foreach ($uCPR as $cpr) {
                $cprKeys[] = ['channel' => $cpr['channel'], 'platformId' => $cpr['platformId']];
            }

            $cprMap = self::fetchChanneledEntities(
                $conn,
                $cprKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_price_rules WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledPriceRuleMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];

            foreach ($uCPR as $k => $cpr) {
                $prKey = KeyGenerator::generatePriceRuleKey($cpr['priceRuleId']);
                if (!isset($priceRuleMap['map'][$prKey])) {
                    continue;
                }
                $prId = $priceRuleMap['map'][$prKey]['id'];

                $row = [
                    'price_rule_id' => $prId,
                    'channel' => $cpr['channel'],
                    'platformId' => $cpr['platformId'],
                    'platformCreatedAt' => $cpr['platformCreatedAt'] instanceof \DateTime ? $cpr['platformCreatedAt']->format('Y-m-d H:i:s') : $cpr['platformCreatedAt'],
                    'data' => json_encode($cpr['data'])
                ];

                if (isset($cprMap[$k])) {
                    $row['id'] = $cprMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_price_rules', ['price_rule_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_price_rules', ['id', 'price_rule_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], ['price_rule_id', 'channel', 'platformId', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);

            $cprMap = self::fetchChanneledEntities(
                $conn,
                $cprKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_price_rules WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledPriceRuleMap($manager, $sql, $params)
            );
        }

        // We return the database ID of ChanneledPriceRule so we can use it to attach discounts
        $channeledEntities = [];
        foreach ($uCPR as $k => $cpr) {
            if (isset($cprMap[$k])) {
                $channeledEntities[$cpr['platformId']] = $cprMap[$k]['id'];
            }
        }

        return [
            'priceRules' => array_column($uPR, 'priceRuleId'),
            'channeledPriceRules' => array_column($uCPR, 'platformId'),
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
                    'platformId' => (string)$cd->platformId,
                    'platformCreatedAt' => isset($cd->platformCreatedAt) ? $cd->platformCreatedAt : null,
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

            foreach ($uCDisc as $k => $cd) {
                $dKey = KeyGenerator::generateDiscountKey($cd['code']);
                if (!isset($discountMap['map'][$dKey])) {
                    continue;
                }
                $dId = $discountMap['map'][$dKey]['id'];

                $row = [
                    'discount_id' => $dId,
                    'channeled_price_rule_id' => $channeledPriceRuleDbId,
                    'channel' => $cd['channel'],
                    'platformId' => $cd['platformId'],
                    'code' => $cd['code'],
                    'platformCreatedAt' => $cd['platformCreatedAt'] instanceof \DateTime ? $cd['platformCreatedAt']->format('Y-m-d H:i:s') : $cd['platformCreatedAt'],
                    'data' => json_encode($cd['data'])
                ];

                if (isset($cdMap[$k])) {
                    $row['id'] = $cdMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_discounts', ['discount_id', 'channeled_price_rule_id', 'channel', 'platformId', 'code', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_discounts', ['id', 'discount_id', 'channeled_price_rule_id', 'channel', 'platformId', 'code', 'platformCreatedAt', 'data'], ['discount_id', 'channeled_price_rule_id', 'channel', 'platformId', 'code', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);
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
                $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, ($table === 'price_rules' ? 'priceRuleId' : 'code'), count($chunk));
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
        $uniqueCols = ($table === 'channeled_price_rules' ? ['channel', 'platformId'] : ['channel', 'code']);
        
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
