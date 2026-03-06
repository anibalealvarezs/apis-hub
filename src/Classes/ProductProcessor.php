<?php

namespace Classes;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;

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
            $chan = (string)$cp->channel;

            // Vendors
            $vendorKey = null;
            $vendorName = null;
            if (!empty($cp->vendor->name)) {
                $vendorName = $cp->vendor->name;
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
                        'platformId' => $cp->vendor->platformId ?? $vendorName,
                        'platformCreatedAt' => $cp->vendor->platformCreatedAt ?? null,
                        'data' => $cp->vendor->data ?? [],
                    ];
                }
            }

            // Products
            $pKey = KeyGenerator::generateProductKey((string)$cp->platformId);
            if (!isset($uProd[$pKey])) {
                $uProd[$pKey] = [
                    'productId' => (string)$cp->platformId,
                    'sku' => $cp->sku,
                ];
            }

            $cpKey = KeyGenerator::generateChanneledProductKey($chan, (string)$cp->platformId);
            if (!isset($uCProd[$cpKey])) {
                $uCProd[$cpKey] = [
                    'productId' => (string)$cp->platformId,
                    'vendorRef' => $vendorKey,
                    'channel' => $chan,
                    'platformId' => (string)$cp->platformId,
                    'platformCreatedAt' => $cp->platformCreatedAt ?? null,
                    'data' => $cp->data ?? [],
                ];
            }

            // Variants
            if (!empty($cp->variants)) {
                foreach ($cp->variants as $var) {
                    $varPId = (string)$var->platformId;
                    $vKey = KeyGenerator::generateProductVariantKey($varPId);
                    if (!isset($uVar[$vKey])) {
                        $uVar[$vKey] = [
                            'productVariantId' => $varPId,
                            'sku' => $var->sku ?? '',
                        ];
                    }

                    $cvKey = KeyGenerator::generateChanneledProductVariantKey($chan, $varPId);
                    if (!isset($uCVar[$cvKey])) {
                        $uCVar[$cvKey] = [
                            'variantPId' => $varPId,
                            'channeledProductRef' => $cpKey,
                            'channel' => $chan,
                            'platformId' => $varPId,
                            'platformCreatedAt' => $var->platformCreatedAt ?? null,
                            'data' => $var->data ?? [],
                        ];
                    }
                }
            }

            // Categories
            if (!empty($cp->categories)) {
                foreach ($cp->categories as $cat) {
                    $catPId = (string)$cat->platformId;
                    $cKey = KeyGenerator::generateProductCategoryKey($catPId);
                    if (!isset($uCat[$cKey])) {
                        $uCat[$cKey] = [
                            'productCategoryId' => $catPId,
                            'isSmartCollection' => $cat->isSmartCollection ?? false,
                        ];
                    }

                    $ccKey = KeyGenerator::generateChanneledProductCategoryKey($chan, $catPId);
                    if (!isset($uCCat[$ccKey])) {
                        $uCCat[$ccKey] = [
                            'categoryPId' => $catPId,
                            'channel' => $chan,
                            'platformId' => $catPId,
                            'platformCreatedAt' => $cat->platformCreatedAt ?? null,
                            'data' => $cat->data ?? [],
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
                    'platformId' => $cv['platformId'],
                    'platformCreatedAt' => $cv['platformCreatedAt'] instanceof DateTime ? $cv['platformCreatedAt']->format('Y-m-d H:i:s') : $cv['platformCreatedAt'],
                    'data' => json_encode($cv['data'])
                ];

                if (isset($channeledVendorMap[$k])) {
                    $row['id'] = $channeledVendorMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_vendors', ['vendor_id', 'channel', 'name', 'platformId', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_vendors', ['id', 'vendor_id', 'channel', 'name', 'platformId', 'platformCreatedAt', 'data'], ['vendor_id', 'channel', 'name', 'platformId', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);

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
            $productIds = array_column($uProd, 'productId');
            $productMap = self::fetchAndInsertEntities(
                $conn,
                'products',
                'productId',
                $productIds,
                ['productId', 'sku'],
                [$uProd, fn ($p) => [$p['productId'], $p['sku']]],
                fn ($chunk) => "SELECT id, productId, sku FROM products WHERE productId IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRODUCTS
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
            $updateRows = [];
            foreach ($uCProd as $k => $cp) {
                $pKey = KeyGenerator::generateProductKey($cp['productId']);
                if (!isset($productMap['map'][$pKey])) {
                    continue;
                }
                $productId = $productMap['map'][$pKey]['id'];

                $vendorId = null;
                if ($cp['vendorRef'] && isset($channeledVendorMap[$cp['vendorRef']])) {
                    $vendorId = $channeledVendorMap[$cp['vendorRef']]['id'];
                }

                $row = [
                    'product_id' => $productId,
                    'channeled_vendor_id' => $vendorId,
                    'channel' => $cp['channel'],
                    'platformId' => $cp['platformId'],
                    'platformCreatedAt' => $cp['platformCreatedAt'] instanceof DateTime ? $cp['platformCreatedAt']->format('Y-m-d H:i:s') : $cp['platformCreatedAt'],
                    'data' => json_encode($cp['data'])
                ];

                if (isset($channeledProductMap[$k])) {
                    $row['id'] = $channeledProductMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_products', ['product_id', 'channeled_vendor_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_products', ['id', 'product_id', 'channeled_vendor_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], ['product_id', 'channeled_vendor_id', 'channel', 'platformId', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );
        }

        // ============================================
        // PRODUCT VARIANTS
        // ============================================
        $variantMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uVar)) {
            $variantIds = array_column($uVar, 'productVariantId');
            $variantMap = self::fetchAndInsertEntities(
                $conn,
                'product_variants',
                'productVariantId',
                $variantIds,
                ['productVariantId', 'sku'],
                [$uVar, fn ($v) => [$v['productVariantId'], $v['sku']]],
                fn ($chunk) => "SELECT id, productVariantId, sku FROM product_variants WHERE productVariantId IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getProductVariantMap($manager, $sql, $params)
            );
        }

        // CHANNELED PRODUCT VARIANTS
        $channeledVariantMap = [];
        if (!empty($uCVar)) {
            $cvKeys = [];
            foreach ($uCVar as $cv) {
                $cvKeys[] = ['channel' => $cv['channel'], 'platformId' => $cv['platformId']];
            }
            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCVar as $k => $cv) {
                $vKey = KeyGenerator::generateProductVariantKey($cv['variantPId']);
                if (!isset($variantMap['map'][$vKey])) {
                    continue;
                }
                $variantId = $variantMap['map'][$vKey]['id'];

                $cProductId = null;
                if ($cv['channeledProductRef'] && isset($channeledProductMap[$cv['channeledProductRef']])) {
                    $cProductId = $channeledProductMap[$cv['channeledProductRef']]['id'];
                }

                if (!$cProductId) {
                    continue;
                } // Safety check

                $row = [
                    'product_variant_id' => $variantId,
                    'channeled_product_id' => $cProductId,
                    'channel' => $cv['channel'],
                    'platformId' => $cv['platformId'],
                    'platformCreatedAt' => $cv['platformCreatedAt'] instanceof DateTime ? $cv['platformCreatedAt']->format('Y-m-d H:i:s') : $cv['platformCreatedAt'],
                    'data' => json_encode($cv['data'])
                ];

                if (isset($channeledVariantMap[$k])) {
                    $row['id'] = $channeledVariantMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_product_variants', ['product_variant_id', 'channeled_product_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_product_variants', ['id', 'product_variant_id', 'channeled_product_id', 'channel', 'platformId', 'platformCreatedAt', 'data'], ['product_variant_id', 'channeled_product_id', 'channel', 'platformId', 'platformCreatedAt', 'data', 'updatedAt' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platformId',
                fn ($chunk) => "SELECT id, channel, platformId, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platformId = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );
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

        // CHANNELED CATEGORIES
        $channeledCategoryMap = [];
        if (!empty($uCCat)) {
            $ccKeys = [];
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
                    'isSmartCollection' => 0, // Fallback if needed, check DB schema
                    'platformCreatedAt' => $cc['platformCreatedAt'] instanceof DateTime ? $cc['platformCreatedAt']->format('Y-m-d H:i:s') : $cc['platformCreatedAt'],
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
                        'channeledproductcategory_id' => $ccId,
                        'channeledproduct_id' => $cpId
                    ];
                }
            }
            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_product_categories_channeled_products', ['channeledproductcategory_id', 'channeledproduct_id'], $pivotInserts);
            }
        }

        return [
            'products' => array_column($uProd, 'productId'),
            'channeledProducts' => array_column($uCProd, 'platformId'),
            'vendors' => array_column($uVend, 'name'),
            'productVariants' => array_column($uVar, 'productVariantId'),
            'productCategories' => array_column($uCat, 'productCategoryId'),
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
                $placeholders = implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($insertCols), '?')) . ')'));
                $colsStr = implode(', ', $insertCols);
                $conn->executeStatement("INSERT IGNORE INTO $table ($colsStr) VALUES $placeholders", $params);
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
        $colStr = implode(', ', $columns);
        foreach ($chunks as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $col) {
                    $params[] = $row[$col];
                }
            }
            $placeholders = implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($columns), '?')) . ')'));
            $conn->executeStatement("INSERT IGNORE INTO $table ($colStr) VALUES $placeholders", $params);
        }
    }

    private static function bulkUpsert(\Doctrine\DBAL\Connection $conn, string $table, array $columns, array $updateMap, array $rows, int $chunkSize = 500): void
    {
        if (empty($rows)) {
            return;
        }
        $chunks = array_chunk($rows, $chunkSize);
        $colStr = implode(', ', $columns);

        $updateStrings = [];
        foreach ($updateMap as $key => $val) {
            if (is_int($key)) {
                $updateStrings[] = "$val = VALUES($val)";
            } else {
                $updateStrings[] = "$key = $val";
            }
        }
        $updateClause = implode(', ', $updateStrings);

        foreach ($chunks as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $col) {
                    $params[] = $row[$col] ?? null;
                }
            }
            $placeholders = implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($columns), '?')) . ')'));
            $conn->executeStatement("INSERT INTO $table ($colStr) VALUES $placeholders ON DUPLICATE KEY UPDATE $updateClause", $params);
        }
    }
}
