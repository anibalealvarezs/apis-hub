<?php

namespace Classes;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Helpers\Helpers;

class OrderProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return array
     * @throws Exception
     */
    public static function processOrders(
        ArrayCollection $channeledCollection,
        EntityManager $manager
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $manager->getConnection();

        $uOrd = [];
        $uCOrd = [];
        $uCust = [];
        $uCCust = [];
        $uDisc = [];
        $uCDisc = [];
        $uCProd = [];
        $uCVar = [];

        $pivotOrdProd = []; // channeled_order_id => array of channeled_product_id
        $pivotOrdVar = []; // channeled_order_id => array of channeled_product_variant_id
        $pivotOrdDisc = []; // channeled_order_id => array of channeled_discount_id

        // 1. Extract unique records
        foreach ($channeledCollection as $co) {
            if (!is_object($co)) continue;
            /** @var object{channel: string|int, platformId: string|int, customer: ?object{id: ?string|int, email: ?string}, platformCreatedAt: ?mixed, data: mixed, discountCodes: ?array, lineItems: ?array} $co */
            $chan = (string)$co->channel;

            // Orders
            $oKey = KeyGenerator::generateOrderKey((string)$co->platformId);
            if (!isset($uOrd[$oKey])) {
                $uOrd[$oKey] = [
                    'order_id' => (string)$co->platformId,
                ];
            }

            $coKey = KeyGenerator::generateChanneledOrderKey($chan, (string)$co->platformId);
            if (!isset($uCOrd[$coKey])) {
                // Determine Customer Ref
                $customerRef = null;
                if (!empty($co->customer->id)) {
                    $customerRef = KeyGenerator::generateChanneledCustomerKey($chan, (string)$co->customer->id);
                } elseif (!empty($co->customer->email)) {
                    // Fallback to customer ID based on email if platformId acts differently
                    $customerRef = KeyGenerator::generateChanneledCustomerKey($chan, (string)($co->customer->id ?? ''));
                }

                $uCOrd[$coKey] = [
                    'order_id' => (string)$co->platformId,
                    'customer_ref' => $customerRef,
                    'channel' => $chan,
                    'platform_id' => (string)$co->platformId,
                    'platform_created_at' => isset($co->platformCreatedAt) ? $co->platformCreatedAt : null,
                    'data' => is_object($co->data) ? clone $co->data : (object)($co->data ?? []),
                ];
            } else {
                // Agnostic logic for data merging on duplicate platform entries in the same batch
                $existingData = (array)$uCOrd[$coKey]['data'];
                $newData = (array)$co->data;
                foreach ($newData as $prop => $val) {
                    if (is_array($val) && isset($existingData[$prop]) && is_array($existingData[$prop])) {
                        $existingData[$prop] = Helpers::multiDimensionalArrayUnique(array_merge($existingData[$prop], $val));
                    } else {
                        $existingData[$prop] = $val;
                    }
                }
                $uCOrd[$coKey]['data'] = (object)$existingData;
            }

            // Customers
            if (isset($co->customer->id) || isset($co->customer->email)) {
                $cEmail = $co->customer->email ?? '';
                $cPId = $co->customer->id ?? '';

                if (!empty($cEmail)) {
                    $cKey = KeyGenerator::generateCustomerKey($cEmail);
                    if (!isset($uCust[$cKey])) {
                        $uCust[$cKey] = ['email' => $cEmail];
                    }
                }

                $ccKey = KeyGenerator::generateChanneledCustomerKey($chan, (string)$cPId);
                if (!isset($uCCust[$ccKey])) {
                    $uCCust[$ccKey] = [
                        'customer_email' => $cEmail,
                        'channel' => $chan,
                        'platform_id' => (string)$cPId,
                        'email' => $cEmail,
                        'platform_created_at' => null, 
                        'data' => (object)[]
                    ];
                }
            }

            // Discounts
            if (!empty($co->discountCodes)) {
                if (!isset($pivotOrdDisc[$coKey])) {
                    $pivotOrdDisc[$coKey] = [];
                }
                foreach ($co->discountCodes as $code) {
                    $cdKey = KeyGenerator::generateChanneledDiscountKey($chan, $code);
                    if (!isset($uCDisc[$cdKey])) {
                        $uCDisc[$cdKey] = [
                            'code' => $code,
                            'channel' => $chan,
                            'platform_id' => '0',
                            'platform_created_at' => null,
                            'data' => (object)[]
                        ];
                    }
                    $pivotOrdDisc[$coKey][$cdKey] = $cdKey;
                }
            }

            // Line Items
            if (!empty($co->lineItems)) {
                if (!isset($pivotOrdProd[$coKey])) {
                    $pivotOrdProd[$coKey] = [];
                }
                if (!isset($pivotOrdVar[$coKey])) {
                    $pivotOrdVar[$coKey] = [];
                }

                foreach ($co->lineItems as $li) {
                    $pId = $li['product_id'] ?? null;
                    $vId = $li['variant_id'] ?? null;

                    if ($pId) {
                        $cpKey = KeyGenerator::generateChanneledProductKey($chan, (string)$pId);
                        if (!isset($uCProd[$cpKey])) {
                            $uCProd[$cpKey] = [
                                'channel' => $chan,
                                'platform_id' => (string)$pId,
                                'platform_created_at' => null,
                                'data' => (object)[]
                            ];
                        }
                        $pivotOrdProd[$coKey][$cpKey] = $cpKey;

                        if ($vId) {
                            $cvKey = KeyGenerator::generateChanneledProductVariantKey($chan, (string)$vId);
                            if (!isset($uCVar[$cvKey])) {
                                $uCVar[$cvKey] = [
                                    'channeledProductRef' => $cpKey,
                                    'channel' => $chan,
                                    'platform_id' => (string)$vId,
                                    'platform_created_at' => null,
                                    'data' => (object)[]
                                ];
                            }
                            $pivotOrdVar[$coKey][$cvKey] = $cvKey;
                        }
                    }
                }
            }
        }

        // ============================================
        // CUSTOMERS
        // ============================================
        $customerMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uCust)) {
            $emails = array_column($uCust, 'email');
            $customerMap = self::fetchAndInsertEntities(
                $conn,
                'customers',
                'email',
                $emails,
                ['email'],
                [$uCust, fn ($c) => [$c['email']]],
                fn ($chunk) => "SELECT id, email FROM customers WHERE email IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => self::mapKeys($conn, $sql, $params, fn ($r) => KeyGenerator::generateCustomerKey($r['email']))
            );
        }

        // CHANNELED CUSTOMERS
        $channeledCustomerMap = [];
        if (!empty($uCCust)) {
            $ccKeys = [];
            foreach ($uCCust as $cc) {
                $ccKeys[] = ['channel' => $cc['channel'], 'platform_id' => $cc['platform_id']];
            }
            $channeledCustomerMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_customers WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledCustomerMap($manager, $sql, $params)
            );

            // Upsert CC
            $insertRows = [];
            $updateRows = [];
            foreach ($uCCust as $k => $cc) {
                $customerId = null;
                if (!empty($cc['email'])) {
                    $cKey = KeyGenerator::generateCustomerKey($cc['email']);
                    if (isset($customerMap['map'][$cKey])) {
                        $customerId = $customerMap['map'][$cKey]['id'];
                    }
                }

                $row = [
                    'customer_id' => $customerId,
                    'channel' => $cc['channel'],
                    'email' => $cc['email'],
                    'platform_id' => $cc['platform_id'],
                    'platform_created_at' => $cc['platform_created_at'],
                    'data' => json_encode($cc['data'])
                ];

                if (isset($channeledCustomerMap[$k])) {
                    $row['id'] = $channeledCustomerMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_customers', ['customer_id', 'channel', 'email', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            // Assuming no update for CC in this phase as it's just placeholder

            $channeledCustomerMap = self::fetchChanneledEntities(
                $conn,
                $ccKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_customers WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledCustomerMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED DISCOUNTS
        // ============================================
        $channeledDiscountMap = [];
        if (!empty($uCDisc)) {
            $cdKeys = [];
            foreach ($uCDisc as $cd) {
                $cdKeys[] = ['channel' => $cd['channel'], 'code' => $cd['code']];
            }
            $channeledDiscountMap = self::fetchChanneledEntities(
                $conn,
                $cdKeys,
                'code',
                fn ($chunk) => "SELECT id, channel, code, data FROM channeled_discounts WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND code = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledDiscountMap($manager, $sql, $params)
            );

            $insertRows = [];
            foreach ($uCDisc as $k => $cd) {
                if (!isset($channeledDiscountMap[$k])) {
                    $insertRows[] = [
                        'channel' => $cd['channel'],
                        'platform_id' => $cd['platform_id'],
                        'code' => $cd['code'],
                        'platform_created_at' => $cd['platform_created_at'],
                        'data' => json_encode($cd['data'])
                    ];
                }
            }

            self::bulkInsert($conn, 'channeled_discounts', ['channel', 'platform_id', 'code', 'platform_created_at', 'data'], $insertRows);

            $channeledDiscountMap = self::fetchChanneledEntities(
                $conn,
                $cdKeys,
                'code',
                fn ($chunk) => "SELECT id, channel, code, data FROM channeled_discounts WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND code = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledDiscountMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED PRODUCTS (Placeholders)
        // ============================================
        $channeledProductMap = [];
        if (!empty($uCProd)) {
            $cpKeys = [];
            foreach ($uCProd as $cp) {
                $cpKeys[] = ['channel' => $cp['channel'], 'platform_id' => $cp['platform_id']];
            }
            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );

            $insertRows = [];
            foreach ($uCProd as $k => $cp) {
                if (!isset($channeledProductMap[$k])) {
                    $insertRows[] = [
                        'channel' => $cp['channel'],
                        'platform_id' => $cp['platform_id'],
                        'platform_created_at' => $cp['platform_created_at'],
                        'data' => json_encode($cp['data'])
                    ];
                }
            }
            self::bulkInsert($conn, 'channeled_products', ['channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);

            $channeledProductMap = self::fetchChanneledEntities(
                $conn,
                $cpKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_products WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED PRODUCT VARIANTS (Placeholders)
        // ============================================
        $channeledVariantMap = [];
        if (!empty($uCVar)) {
            $cvKeys = [];
            foreach ($uCVar as $cv) {
                $cvKeys[] = ['channel' => $cv['channel'], 'platform_id' => $cv['platform_id']];
            }
            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );

            $insertRows = [];
            foreach ($uCVar as $k => $cv) {
                if (!isset($channeledVariantMap[$k])) {
                    $cProdId = null;
                    if (isset($channeledProductMap[$cv['channeledProductRef']])) {
                        $cProdId = $channeledProductMap[$cv['channeledProductRef']]['id'];
                    }

                    $insertRows[] = [
                        'channeled_product_id' => $cProdId,
                        'channel' => $cv['channel'],
                        'platform_id' => $cv['platform_id'],
                        'platform_created_at' => $cv['platform_created_at'],
                        'data' => json_encode($cv['data'])
                    ];
                }
            }
            self::bulkInsert($conn, 'channeled_product_variants', ['channeled_product_id', 'channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);

            $channeledVariantMap = self::fetchChanneledEntities(
                $conn,
                $cvKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_product_variants WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledProductVariantMap($manager, $sql, $params)
            );
        }

        // ============================================
        // ORDERS
        // ============================================
        $orderMap = ['map' => [], 'mapReverse' => []];
        if (!empty($uOrd)) {
            $orderIds = array_column($uOrd, 'order_id');
            $orderMap = self::fetchAndInsertEntities(
                $conn,
                'orders',
                'order_id',
                $orderIds,
                ['order_id'],
                [$uOrd, fn ($o) => [$o['orderId']]],
                fn ($chunk) => "SELECT id, order_id FROM orders WHERE order_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ")",
                fn ($conn, $sql, $params) => MapGenerator::getOrderMap($manager, $sql, $params)
            );
        }

        // ============================================
        // CHANNELED ORDERS
        // ============================================
        $channeledOrderMap = [];
        if (!empty($uCOrd)) {
            $coKeys = [];
            foreach ($uCOrd as $coRow) {
                $coKeys[] = ['channel' => $coRow['channel'], 'platform_id' => $coRow['platform_id']];
            }
            $channeledOrderMap = self::fetchChanneledEntities(
                $conn,
                $coKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_orders WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledOrderMap($manager, $sql, $params)
            );

            $insertRows = [];
            $updateRows = [];
            foreach ($uCOrd as $k => $co) {
                $oKey = KeyGenerator::generateOrderKey($co['order_id']);
                if (!isset($orderMap['map'][$oKey])) {
                    continue;
                }
                $orderId = $orderMap['map'][$oKey]['id'];

                $cCustId = null;
                if (!empty($co['customer_ref']) && isset($channeledCustomerMap[$co['customer_ref']])) {
                    $cCustId = $channeledCustomerMap[$co['customer_ref']]['id'];
                }

                $row = [
                    'order_id' => $orderId,
                    'channeled_customer_id' => $cCustId,
                    'channel' => $co['channel'],
                    'platform_id' => $co['platform_id'],
                    'platform_created_at' => $co['platform_created_at'] instanceof DateTime ? $co['platform_created_at']->format('Y-m-d H:i:s') : $co['platform_created_at'],
                    'data' => json_encode($co['data'])
                ];

                if (isset($channeledOrderMap[$k])) {
                    $row['id'] = $channeledOrderMap[$k]['id'];
                    $updateRows[] = $row;
                } else {
                    $insertRows[] = $row;
                }
            }

            self::bulkInsert($conn, 'channeled_orders', ['order_id', 'channeled_customer_id', 'channel', 'platform_id', 'platform_created_at', 'data'], $insertRows);
            self::bulkUpsert($conn, 'channeled_orders', ['id', 'order_id', 'channeled_customer_id', 'channel', 'platform_id', 'platform_created_at', 'data'], ['order_id', 'channeled_customer_id', 'channel', 'platform_id', 'platform_created_at', 'data', 'updated_at' => 'CURRENT_TIMESTAMP'], $updateRows);

            $channeledOrderMap = self::fetchChanneledEntities(
                $conn,
                $coKeys,
                'platform_id',
                fn ($chunk) => "SELECT id, channel, platform_id, data FROM channeled_orders WHERE " . implode(' OR ', array_fill(0, count($chunk), "(channel = ? AND platform_id = ?)")),
                fn ($conn, $sql, $params) => MapGenerator::getChanneledOrderMap($manager, $sql, $params)
            );
        }

        // ============================================
        // PIVOT TABLES
        // ============================================

        // Products
        if (!empty($pivotOrdProd)) {
            $pivotInserts = [];
            foreach ($pivotOrdProd as $coKey => $cpKeys) {
                if (!isset($channeledOrderMap[$coKey])) {
                    continue;
                }
                $coId = $channeledOrderMap[$coKey]['id'];

                foreach ($cpKeys as $cpKey) {
                    if (!isset($channeledProductMap[$cpKey])) {
                        continue;
                    }
                    $cpId = $channeledProductMap[$cpKey]['id'];
                    $pivotInserts[] = [
                        'channeled_order_id' => $coId,
                        'channeled_product_id' => $cpId
                    ];
                }
            }
            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_order_channeled_products', ['channeled_order_id', 'channeled_product_id'], $pivotInserts);
            }
        }

        // Variants
        if (!empty($pivotOrdVar)) {
            $pivotInserts = [];
            foreach ($pivotOrdVar as $coKey => $cvKeys) {
                if (!isset($channeledOrderMap[$coKey])) {
                    continue;
                }
                $coId = $channeledOrderMap[$coKey]['id'];

                foreach ($cvKeys as $cvKey) {
                    if (!isset($channeledVariantMap[$cvKey])) {
                        continue;
                    }
                    $cvId = $channeledVariantMap[$cvKey]['id'];
                    $pivotInserts[] = [
                        'channeled_order_id' => $coId,
                        'channeled_product_variant_id' => $cvId
                    ];
                }
            }
            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_order_channeled_product_variants', ['channeled_order_id', 'channeled_product_variant_id'], $pivotInserts);
            }
        }

        // Discounts
        if (!empty($pivotOrdDisc)) {
            $pivotInserts = [];
            foreach ($pivotOrdDisc as $coKey => $cdKeys) {
                if (!isset($channeledOrderMap[$coKey])) {
                    continue;
                }
                $coId = $channeledOrderMap[$coKey]['id'];

                foreach ($cdKeys as $cdKey) {
                    if (!isset($channeledDiscountMap[$cdKey])) {
                        continue;
                    }
                    $cdId = $channeledDiscountMap[$cdKey]['id'];
                    $pivotInserts[] = [
                        'channeled_order_id' => $coId,
                        'channeled_discount_id' => $cdId
                    ];
                }
            }
            if (!empty($pivotInserts)) {
                self::bulkInsert($conn, 'channeled_order_channeled_discounts', ['channeled_order_id', 'channeled_discount_id'], $pivotInserts);
            }
        }

        return [
            'orders' => array_column($uOrd, 'order_id'),
            'channeledOrders' => array_column($uCOrd, 'platform_id'),
            'channels' => array_unique(array_column($uCOrd, 'channel')),
            'discounts' => array_column($uCDisc, 'code'),
            'channeledCustomers' => array_column($uCCust, 'platform_id'),
            'channeledProducts' => array_column($uCProd, 'platform_id'),
            'channeledVariants' => array_column($uCVar, 'platform_id'),
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
        
        $buffer = [];
        $count = 0;
        $numCols = count($insertCols);
        $chunkLimit = floor(30000 / $numCols);

        foreach ($dataArray as $key => $item) {
            if (!isset($map['map'][$key])) {
                $row = $mappingFn($item);
                foreach ($row as $val) {
                    $buffer[] = $val;
                }
                $count++;

                if ($count >= $chunkLimit) {
                    $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, ($table === 'customers' ? 'email' : 'order_id'), $count);
                    $conn->executeStatement($sql, $buffer);
                    $buffer = [];
                    $count = 0;
                }
            }
        }

        if ($count > 0) {
            $sql = Helpers::buildInsertIgnoreSql($table, $insertCols, ($table === 'customers' ? 'email' : 'order_id'), $count);
            $conn->executeStatement($sql, $buffer);
        }

        // Re-fetch EVERYTHING to update the map with all IDs
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($chunks as $chunk) {
            $sql = $sqlGenerator($chunk);
            $fetched = $mapGenerator($conn, $sql, $chunk);
            $map['map'] = array_merge($map['map'], $fetched['map']);
            $map['mapReverse'] = $map['mapReverse'] + $fetched['mapReverse'];
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

    private static function mapKeys(\Doctrine\DBAL\Connection $conn, string $sql, array $params, callable $keyGen): array
    {
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        $res = ['map' => [], 'mapReverse' => []];
        foreach ($rows as $row) {
            $key = $keyGen($row);
            $res['map'][$key] = $row;
            $res['mapReverse'][$row['id']] = $key;
        }
        return $res;
    }

    private static function bulkInsert(\Doctrine\DBAL\Connection $conn, string $table, array $columns, array $rows, int $chunkSize = 500): void
    {
        if (empty($rows)) {
            return;
        }
        $chunks = array_chunk($rows, $chunkSize);
        $uniqueCols = ['platform_id', 'channel'];
        if ($table === 'channeled_discounts') { $uniqueCols = ['code', 'channel']; }
        if (str_contains($table, '_channeled_')) {
            if ($table === 'channeled_order_channeled_products') { $uniqueCols = ['channeled_order_id', 'channeled_product_id']; }
            if ($table === 'channeled_order_channeled_product_variants') { $uniqueCols = ['channeled_order_id', 'channeled_product_variant_id']; }
            if ($table === 'channeled_order_channeled_discounts') { $uniqueCols = ['channeled_order_id', 'channeled_discount_id']; }
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
