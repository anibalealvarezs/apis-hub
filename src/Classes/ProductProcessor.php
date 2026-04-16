<?php

namespace Classes;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Helpers\Helpers;

class ProductProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return array
     * @throws Exception
     */
    public static function processProducts(
        ArrayCollection $channeledCollection,
        EntityManager $manager
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $manager->getConnection();

        $uProd = [];
        $uCProd = [];
        $uVend = [];
        $uCVend = [];
        $uVar = [];
        $uCVar = [];
        $uCat = [];
        $uCCat = [];
        $pivotProdCat = []; // channeled_product_id => array of channeled_category_ids

        // 1. Extract unique records
        foreach ($channeledCollection as $cp) {
            if (!$cp) continue;
            $cpObj = (object)$cp;
            /** @var object{channel: mixed, vendor: ?object{name: ?string, platformId: ?string, platformCreatedAt: ?mixed, data: mixed}, platformId: mixed, sku: ?string, platformCreatedAt: ?mixed, data: mixed, variants: ?array, categories: ?array} $cpObj */
            
            $chan = (string)($cpObj->channel ?? '');

            // Vendors
            $vendorKey = null;
            $vendorName = null;
            if (!empty($cpObj->vendor->name ?? null)) {
                $vendorName = (string)$cpObj->vendor->name;
                $vKey = KeyGenerator::generateVendorKey($vendorName);
                if (!isset($uVend[$vKey])) {
                    $uVend[$vKey] = ['name' => $vendorName];
                }

                $cvKey = KeyGenerator::generateChanneledVendorKey($chan, $vendorName);
                $vendorKey = $cvKey;
                if (!isset($uCVend[$cvKey])) {
                    $uCVend[$cvKey] = [
                        'vendor_name' => $vendorName, // Reference for lookup later
                        'channel' => $chan,
                        'name' => $vendorName,
                        'platform_id' => $cpObj->vendor->platformId ?? $vendorName,
                        'platform_created_at' => $cpObj->vendor->platformCreatedAt ?? null,
                        'data' => $cpObj->vendor->data ?? [],
                    ];
                }
            }

            // Products
            $pId = (string)($cpObj->platformId ?? '');
            $pKey = KeyGenerator::generateProductKey($pId);
            if (!isset($uProd[$pKey])) {
                $uProd[$pKey] = [
                    'product_id' => $pId,
                    'sku' => $cpObj->sku ?? '',
                ];
            }

            $cpKey = KeyGenerator::generateChanneledProductKey($chan, $pId);
            if (!isset($uCProd[$cpKey])) {
                $uCProd[$cpKey] = [
                    'product_id' => $pId,
                    'vendorRef' => $vendorKey,
                    'channel' => $chan,
                    'platform_id' => $pId,
                    'platform_created_at' => $cpObj->platformCreatedAt ?? null,
                    'data' => $cpObj->data ?? [],
                ];
            }

            // Variants
            if (!empty($cpObj->variants)) {
                foreach ($cpObj->variants as $var) {
                    if (!$var) continue;
                    $varObj = (object)$var;
                    /** @var object{platformId: mixed, sku: ?string, platformCreatedAt: ?mixed, data: mixed} $varObj */
                    
                    $varPId = (string)($varObj->platformId ?? '');
                    $vKey = KeyGenerator::generateProductVariantKey($varPId);
                    if (!isset($uVar[$vKey])) {
                        $uVar[$vKey] = [
                            'productVariantId' => $varPId,
                            'sku' => $varObj->sku ?? '',
                        ];
                    }

                    $cvKey = KeyGenerator::generateChanneledProductVariantKey($chan, $varPId);
                    if (!isset($uCVar[$cvKey])) {
                        $uCVar[$cvKey] = [
                            'variantPId' => $varPId,
                            'channeledProductRef' => $cpKey,
                            'channel' => $chan,
                            'platform_id' => $varPId,
                            'platform_created_at' => $varObj->platformCreatedAt ?? null,
                            'data' => $varObj->data ?? [],
                        ];
                    }
                }
            }

            // Categories
            if (!empty($cpObj->categories)) {
                foreach ($cpObj->categories as $cat) {
                    if (!$cat) continue;
                    $catObj = (object)$cat;
                    /** @var object{platformId: mixed, isSmartCollection: ?bool, platformCreatedAt: ?mixed, data: mixed} $catObj */
                    
                    $catPId = (string)($catObj->platformId ?? '');
                    $cKey = KeyGenerator::generateProductCategoryKey($catPId);
                    if (!isset($uCat[$cKey])) {
                        $uCat[$cKey] = [
                            'productCategoryId' => $catPId,
                            'isSmartCollection' => $catObj->isSmartCollection ?? false,
                        ];
                    }

                    $ccKey = KeyGenerator::generateChanneledProductCategoryKey($chan, $catPId);
                    if (!isset($uCCat[$ccKey])) {
                        $uCCat[$ccKey] = [
                            'categoryPId' => $catPId,
                            'channel' => $chan,
                            'platform_id' => $catPId,
                            'platform_created_at' => $catObj->platformCreatedAt ?? null,
                            'data' => $catObj->data ?? [],
                        ];
                    }

                    if (!isset($pivotProdCat[$cpKey])) {
                        $pivotProdCat[$cpKey] = [];
                    }
                    $pivotProdCat[$cpKey][$ccKey] = $ccKey;
                }
            }
        }

        // ============================================
        // VENDORS
        // ============================================
        $vendorMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uVend)) {
            $vendorNames = array_column($uVend, 'name');
            $vendorMap = self::fetchAndInsertEntities(
                $conn,
                'vendors',
                'name',
                $vendorNames,
                ['name'],
                [$uVend, fn ($v) => [$v['name']]],
                fn ($chunk) => "SELECT id, name FROM vendors WHERE name IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getVendorMap($manager, $sql, $params)
            );
        }

        // CHANNELED VENDORS
        $channeledVendorMap = [];
        if (!empty($uCVend)) {
            $cvKeys = [];
            foreach ($uCVend as $cv) {
                $cvKeys[] = ['channel' => $cv['channel'], 'name' => $cv['name']];
            }
            $channeledVendorMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'name',
                fn ($chunk) => "SELECT id, channel, name, data FROM channeled_vendors WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND name = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledVendorMap($manager, $sql, $params)
            );

            // Upsert
            $insertRows = [];
            $updateRows = [];
            foreach ($uCVend as $k => $cv) {
                $vKey = KeyGenerator::generateVendorKey($cv['vendor_name']);
                if (!isset($vendorMap['map'][$vKey])) {
                    continue;
                }
                $vendorId = $vendorMap['map'][$vKey]['id'];

                $row = [
                    'vendor_id' => $vendorId,
                    'channel' => $cv['channel'],
                    'name' => $cv['name'],
                    'platform_id' => $cv['platform_id'],
                    'platform_created_at' => $cv['platform_created_at'] instanceof DateTime ? $cv['platform_created_at']->format('Y-m-d H:i:s') : $cv['platform_created_at'],
                    'data' => json_encode($cv['data'])
                ];

                if (isset($channeledVendorMap[$k])) {
                    $row['id'] = $channeledVendorMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_vendors', ['vendor_id', 'channel', 'name', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_vendors', ['id', 'vendor_id', 'channel', 'name', 'platform_id', 'platform_created_at', 'data'], ['vendor_id', 'channel', 'name', 'platform_id', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            // Re-fetch maps to have IDs
            $channeledVendorMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'name',
                fn ($chunk) => "SELECT id, channel, name, data FROM channeled_vendors WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND name = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledVendorMap($manager, $sql, $params)
            );
        }

        // ============================================
        // PRODUCTS
        // ============================================
        $productMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uProd)) {
            $productIds = array_column($uProd, 'product_id');
            $productMap = self::fetchAndInsertEntities(
                $conn,
                'products',
                'product_id',
                $productIds,
                ['product_id', 'sku'],
                [$uProd, fn ($p) => [$p['product_id'], $p['sku']]],
                fn ($chunk) => "SELECT id, product_id, sku FROM products WHERE product_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRODUCTS
        $channeledProductMap = [];
        if (!empty($uCProd)) {
            $cpKeys = [];
            foreach ($uCProd as $cpRow) {
                $cpKeys[] = ['channel' => $cpRow['channel'], 'platform_id' => $cpRow['platform_id']];
            }
            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCProd as $k => $cpRow) {
                $pKey = KeyGenerator::generateProductKey($cpRow['product_id']);
                if (!isset($productMap['map'][$pKey])) {
                    continue;
                }
                $productId = $productMap['map'][$pKey]['id'];

                $vendorId = null;
                if ($cpRow['vendorRef'] && isset($channeledVendorMap[$cpRow['vendorRef']])) {
                    $vendorId = $channeledVendorMap[$cpRow['vendorRef']]['id'];
                }

                $row = [
                    'product_id' => $productId,
                    'channeled_vendor_id' => $vendorId,
                    'channel' => $cpRow['channel'],
                    'platform_id' => $cpRow['platform_id'],
                    'platform_created_at' => $cpRow['platform_created_at'] instanceof DateTime ? $cpRow['platform_created_at']->format('Y-m-d H:i:s') : $cpRow['platform_created_at'],
                    'data' => json_encode($cpRow['data'])
                ];

                if (isset($channeledProductMap[$k])) {
                    $row['id'] = $channeledProductMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_products', ['product_id', 'channeled_vendor_id', 'channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_products', ['id', 'product_id', 'channeled_vendor_id', 'channel', 'platform_id', 'platform_created_at', 'data'], ['product_id', 'channeled_vendor_id', 'channel', 'platform_id', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );
        }

        // ============================================
        // PRODUCT VARIANTS
        // ============================================
        $variantMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uVar)) {
            $variantIds = array_column($uVar, 'product_variant_id');
            $variantMap = self::fetchAndInsertEntities(
                $conn,
                'product_variants',
                'product_variant_id',
                $variantIds,
                ['product_variant_id', 'sku'],
                [$uVar, fn ($v) => [$v['product_variant_id'], $v['sku']]],
                fn ($chunk) => "SELECT id, product_variant_id, sku FROM product_variants WHERE product_variant_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductVariantMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRODUCT VARIANTS
        $channeledVariantMap = [];
        if (!empty($uCVar)) {
            $cvKeys = [];
            foreach ($uCVar as $cvRow) {
                $cvKeys[] = ['channel' => $cvRow['channel'], 'platform_id' => $cvRow['platform_id']];
            }
            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCVar as $k => $cvRow) {
                $vKey = KeyGenerator::generateProductVariantKey($cvRow['variantPId']);
                if (!isset($variantMap['map'][$vKey])) {
                    continue;
                }
                $variantId = $variantMap['map'][$vKey]['id'];

                $cProductId = null;
                if ($cvRow['channeledProductRef'] && isset($channeledProductMap[$cvRow['channeledProductRef']])) {
                    $cProductId = $channeledProductMap[$cvRow['channeledProductRef']]['id'];
                }

                if (!$cProductId) {
                    continue;
                } // Safety check

                $row = [
                    'product_variant_id' => $variantId,
                    'channeled_product_id' => $cProductId,
                    'channel' => $cvRow['channel'],
                    'platform_id' => $cvRow['platform_id'],
                    'platform_created_at' => $cvRow['platform_created_at'] instanceof DateTime ? $cvRow['platform_created_at']->format('Y-m-d H:i:s') : $cvRow['platform_created_at'],
                    'data' => json_encode($cvRow['data'])
                ];

                if (isset($channeledVariantMap[$k])) {
                    $row['id'] = $channeledVariantMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_product_variants', ['product_variant_id', 'channeled_product_id', 'channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_product_variants', ['id', 'product_variant_id', 'channeled_product_id', 'channel', 'platform_id', 'platform_created_at', 'data'], ['product_variant_id', 'channeled_product_id', 'channel', 'platform_id', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CATEGORIES
        // ============================================
        $categoryMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uCat)) {
            $catIds = array_column($uCat, 'product_category_id');
            $categoryMap = self::fetchAndInsertEntities(
                $conn,
                'product_categories',
                'product_category_id',
                $catIds,
                ['product_category_id', 'isSmartCollection'],
                [$uCat, fn ($c) => [$c['product_category_id'], $c['isSmartCollection'] ? 1 : 0]],
                fn ($chunk) => "SELECT id, product_category_id FROM product_categories WHERE product_category_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductCategoryMap($manager, $sql, $params)
            );
        }

        // CHANNELED CATEGORIES
        $channeledCategoryMap = [];
        if (!empty($uCCat)) {
            $ccKeys = [];
            foreach ($uCCat as $ccRow) {
                $ccKeys[] = ['channel' => $ccRow['channel'], 'platform_id' => $ccRow['platform_id']];
            }
            $channeledCategoryMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_categories WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductCategoryMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCCat as $k => $ccRow) {
                $cKey = KeyGenerator::generateProductCategoryKey($ccRow['categoryPId']);
                if (!isset($categoryMap['map'][$cKey])) {
                    continue;
                }
                $categoryId = $categoryMap['map'][$cKey]['id'];

                $row = [
                    'product_category_id' => $categoryId,
                    'channel' => $ccRow['channel'],
                    'platform_id' => $ccRow['platform_id'],
                    'is_smart_collection' => 0, // Fallback if needed, check DB schema
                    'platform_created_at' => $ccRow['platform_created_at'] instanceof DateTime ? $ccRow['platform_created_at']->format('Y-m-d H:i:s') : $ccRow['platform_created_at'],
                    'data' => json_encode($ccRow['data'])
                ];

                if (isset($channeledCategoryMap[$k])) {
                    $row['id'] = $channeledCategoryMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_product_categories', ['product_category_id', 'channel', 'platform_id', 'is_smart_collection', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_product_categories', ['id', 'product_category_id', 'channel', 'platform_id', 'is_smart_collection', 'platform_created_at', 'data'], ['product_category_id', 'channel', 'platform_id', 'is_smart_collection', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledCategoryMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_categories WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductCategoryMap($manager, $sql, $params)
            );
        }

        // ============================================
        // PIVOT TABLE: Products <-> Categories
        // ============================================
        if (!empty($pivotProdCat)) {
            $pivotInserts = [];
            foreach ($pivotProdCat as $cpKey => $ccKeys) {
                if (!isset($channeledProductMap[$cpKey])) {
                    continue;
                }
                $cpId = $channeledProductMap[$cpKey]['id'];

                foreach ($ccKeys as $ccKey) {
                    if (!isset($channeledCategoryMap[$ccKey])) {
                        continue;
                    }
                    $ccId = $channeledCategoryMap[$ccKey]['id'];
                    // Using IGNORE to skip existing relationships safely
                    $pivotInserts[] = [
                        'channeled_product_category_id' => $ccId,
                        'channeled_product_id' => $cpId
                    ];
                }
            }
            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_product_categories_channeled_products', ['channeled_product_category_id', 'channeled_product_id'], $pivotInserts);
            }
        }

        return [
            'products' => array_column($uProd, 'product_id'),
            'channeledProducts' => array_column($uCProd, 'platform_id'),
            'vendors' => array_column($uVend, 'name'),
            'productVariants' => array_column($uVar, 'product_variant_id'),
            'productCategories' => array_column($uCat, 'product_category_id'),
            'channels' => array_unique(array_column($uCProd, 'channel'))
        ];
    }

    /**
     * @param \Doctrine\DBAL\Connection $conn
     * @param string $table
     * @param string $lookupField
     * @param array $lookups
     * @param array $insertCols
     * @param array $dataSource [dataArray, mappingFunction]
     * @param callable $sqlGenerator
     * @param callable $mapGenerator
     * @return array
     * @throws Exception
     */
    private static function fetchAndInsertEntities(\Doctrine\DBAL\Connection $conn, string $table, string $lookupField, array $lookups, array $insertCols, array $dataSource, callable $sqlGenerator, callable $mapGenerator): array
    {
        $chunks = array_chunk($lookups, 1000);
        $map = ['map' => [], 'mapReverse' => []];

        // Fetch existing
        foreach ($chunks as $chunk) {
            $sql = $sqlGenerator($chunk);
            $fetched = $mapGenerator($conn, $sql, $chunk);
            $map['map'] = array_merge($map['map'], $fetched['map']);
            $map['mapReverse'] = $map['mapReverse'] + $fetched['mapReverse'];
        }

        // Find missing
        $dataArray = $dataSource[0];
        $mappingFn = $dataSource[1];
        $toInsert = [];

        foreach ($dataArray as $key => $item) {
            if (!isset($map['map'][$key])) {
                $toInsert[] = $mappingFn($item);
            }
        }

        // Insert missing
        if (!empty($toInsert)) {
            $insertChunks = array_chunk($toInsert, 1000); // chunking raw arrays
            foreach ($insertChunks as $chunk) {
                // Flatten the chunk array for parameters
                $params = [];
                foreach ($chunk as $row) {
                    $params = array_merge($params, $row);
                }
                $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, ($table === 'vendors' ? 'name' : ($table === 'products' ? 'product_id' : ($table === 'product_variants' ? 'product_variant_id' : 'product_category_id'))), count($chunk));
                $conn->executeStatement($sql, $params);
            }

            // Re-fetch to update map with inserted IDs
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
        $uniqueCols = ($table === 'channeled_vendors' ? ['name', 'channel'] : ['platform_id', 'channel']);
        if ($table === 'channeled_product_categories_channeled_products') {
            $uniqueCols = ['channeled_product_category_id', 'channeled_product_id'];
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
        
        // Convert updateMap to updateCols array (ignoring val since Helpers handles CURRENT_TIMESTAMP)
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
            // All bulkUpsert calls in this class currently use 'id' as unique because they are updates of existing IDs
            $sql = Helpers::buildUpsertSql($table, $columns, $updateCols, ['id'], count($chunk));
            $conn->executeStatement($sql, $params);
        }
    }
}
