<?php

namespace Classes;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;

class ProductCategoryProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param array|null $collects
     * @param EntityManager $manager
     * @return array
     * @throws Exception
     */
    public static function processCategories(
        ArrayCollection $channeledCollection,
        ?array $collects,
        EntityManager $manager
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $manager->getConnection();

        $uCat = [];
        $uCCat = [];
        $uCProd = []; // for collects

        foreach ($channeledCollection as $cpc) {
            $chan = (string)$cpc->channel;
            $catPId = (string)$cpc->platformId;

            $cKey = KeyGenerator::generateProductCategoryKey($catPId);
            if (!isset($uCat[$cKey])) {
                $uCat[$cKey] = [
                    'productCategoryId' => $catPId,
                    'isSmartCollection' => $cpc->isSmartCollection ?? false,
                ];
            }

            $ccKey = KeyGenerator::generateChanneledProductCategoryKey($chan, $catPId);
            if (!isset($uCCat[$ccKey])) {
                $uCCat[$ccKey] = [
                    'categoryPId' => $catPId,
                    'channel' => $chan,
                    'platformId' => $catPId,
                    'platformCreatedAt' => isset($cpc->platformCreatedAt) ? $cpc->platformCreatedAt : null,
                    'isSmartCollection' => $cpc->isSmartCollection ?? false,
                    'data' => is_object($cpc->data) ? clone $cpc->data : (object)($cpc->data ?? []),
                ];
            }

            if ($collects && isset($collects[$catPId])) {
                foreach ($collects[$catPId] as $prodId) {
                    $cpKey = KeyGenerator::generateChanneledProductKey($chan, (string)$prodId);
                    if (!isset($uCProd[$cpKey])) {
                        $uCProd[$cpKey] = [
                            'channel' => $chan,
                            'platformId' => (string)$prodId,
                        ];
                    }
                }
            }
        }

        // ============================================
        // CATEGORIES
        // ============================================
        $categoryMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uCat)) {
            $catIds = array_column($uCat, 'productCategoryId');
            $categoryMap = self::fetchAndInsertEntities(
                $conn,
                'product_categories',
                'productCategoryId',
                $catIds,
                ['productCategoryId', 'isSmartCollection'],
                [$uCat, fn ($c) => [$c['productCategoryId'], $c['isSmartCollection'] ? 1 : 0]],
                fn ($chunk) => "SELECT id, productCategoryId FROM product_categories WHERE productCategoryId IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductCategoryMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED CATEGORIES
        // ============================================
        $channeledCategoryMap = [];
        $ccKeys = [];
        if (!empty($uCCat)) {
            foreach ($uCCat as $cc) {
                $ccKeys[] = ['channel' => $cc['channel'], 'platformId' => $cc['platformId']];
            }
            $channeledCategoryMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_product_categories WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductCategoryMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCCat as $k => $cc) {
                $cKey = KeyGenerator::generateProductCategoryKey($cc['categoryPId']);
                if (!isset($categoryMap['map'][$cKey])) {
                    continue;
                }
                $categoryId = $categoryMap['map'][$cKey]['id'];

                $row = [
                    'product_category_id' => $categoryId,
                    'channel' => $cc['channel'],
                    'platformId' => $cc['platformId'],
                    'isSmartCollection' => $cc['isSmartCollection'] ? 1 : 0,
                    'platformCreatedAt' => $cc['platformCreatedAt'] instanceof \DateTime ? $cc['platformCreatedAt']->format('Y-m-d H:i:s') : $cc['platformCreatedAt'],
                    'data' => json_encode($cc['data'])
                ];

                if (isset($channeledCategoryMap[$k])) {
                    $row['id'] = $channeledCategoryMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_product_categories', ['product_category_id', 'channel', 'platformId', 'isSmartCollection', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_product_categories', ['id', 'product_category_id', 'channel', 'platformId', 'isSmartCollection', 'platformCreatedAt', 'data'], ['product_category_id', 'channel', 'platformId', 'isSmartCollection', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledCategoryMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_product_categories WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductCategoryMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED PRODUCTS (For collects, placeholders)
        // ============================================
        $channeledProductMap = [];
        if (!empty($uCProd)) {
            $cpKeys = [];
            foreach ($uCProd as $cp) {
                $cpKeys[] = ['channel' => $cp['channel'], 'platformId' => $cp['platformId']];
            }
            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );

            $insertRows = [];
            foreach ($uCProd as $k => $cp) {
                if (!isset($channeledProductMap[$k])) {
                    $insertRows[] = [
                        'channel' => $cp['channel'],
                        'platformId' => $cp['platformId'],
                        'data' => json_encode([])
                    ];
                }
            }
            self::bulkInsert($conn, 'channeled_products', ['channel', 'platformId', 'data'], $insertRows);

            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );
        }

        // ============================================
        // COLLECTS (PIVOT)
        // ============================================
        if ($collects && !empty($uCCat)) {
            $pivotInserts = [];
            foreach ($collects as $catPId => $prodIds) {
                foreach ($prodIds as $prodId) {
                    $chan = (string)$channeledCollection->first()->channel; // Assuming single channel per run

                    $ccKey = KeyGenerator::generateChanneledProductCategoryKey($chan, (string)$catPId);
                    $cpKey = KeyGenerator::generateChanneledProductKey($chan, (string)$prodId);

                    if (isset($channeledCategoryMap[$ccKey]) && isset($channeledProductMap[$cpKey])) {
                        $pivotInserts[] = [
                            'channeledproductcategory_id' => $channeledCategoryMap[$ccKey]['id'],
                            'channeledproduct_id' => $channeledProductMap[$cpKey]['id']
                        ];
                    }
                }
            }

            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_product_categories_channeled_products', ['channeledproductcategory_id', 'channeledproduct_id'], $pivotInserts);
            }
        }

        return [
            'productCategories' => array_column($uCat, 'productCategoryId'),
            'channeledProductCategories' => array_column($uCCat, 'platformId'),
            'channels' => array_unique(array_column($uCCat, 'channel')),
            'channeledProducts' => array_column($uCProd, 'platformId'),
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
                $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, 'productCategoryId', count($chunk));
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
        $uniqueCols = ['channel', 'platformId'];
        if ($table === 'channeled_product_categories_channeled_products') {
            $uniqueCols = ['channeledproductcategory_id', 'channeledproduct_id'];
        }

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
