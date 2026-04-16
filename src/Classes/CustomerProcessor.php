<?php

namespace Classes;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Carbon\Carbon;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CustomerProcessor
{
    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @param LoggerInterface|null $logger
     * @return array
     * @throws Exception
     */
    public static function processCustomers(
        ArrayCollection $channeledCollection,
        EntityManager $manager,
        ?LoggerInterface $logger = null
    ): array {
        if ($channeledCollection->isEmpty()) {
            return [];
        }

        $uniqueCustomers = [];
        $uniqueChanneledCustomers = [];

        // 1. Extract unique records
        /** @var \stdClass&object{email: string, channel: string, platformId: string, platformCreatedAt: string|\DateTime, data: array} $channeledCustomer */
        foreach ($channeledCollection as $channeledCustomer) {
            if (!is_object($channeledCustomer)) {
                continue;
            }
            /** @var object{email: string, channel: string|int, platformId: string|int, platformCreatedAt: string|\DateTime, data: array} $channeledCustomer */
            if (empty($channeledCustomer->email)) {
                continue;
            }

            $customerKey = KeyGenerator::generateCustomerKey($channeledCustomer->email);
            if (!isset($uniqueCustomers[$customerKey])) {
                $uniqueCustomers[$customerKey] = [
                    'email' => $channeledCustomer->email,
                ];
            }

            $channeledKey = KeyGenerator::generateChanneledCustomerKey($channeledCustomer->channel, $channeledCustomer->platformId);
            if (!isset($uniqueChanneledCustomers[$channeledKey])) {
                $uniqueChanneledCustomers[$channeledKey] = [
                    'customer_email' => $channeledCustomer->email,
                    'channel' => $channeledCustomer->channel,
                    'platform_id' => $channeledCustomer->platformId,
                    'platform_created_at' => $channeledCustomer->platformCreatedAt,
                    'data' => $channeledCustomer->data,
                ];
            } else {
                // Merge addresses in memory if duplicates exist in the same payload
                $existingData = $uniqueChanneledCustomers[$channeledKey]['data'];
                if (isset($channeledCustomer->data['addresses'])) {
                    $existingData['addresses'] = Helpers::multiDimensionalArrayUnique(
                        array_merge(
                            $existingData['addresses'] ?? [],
                            $channeledCustomer->data['addresses']
                        )
                    );
                }
                $uniqueChanneledCustomers[$channeledKey]['data'] = $existingData;
            }
        }

        if (empty($uniqueCustomers)) {
            return [];
        }

        // 2. Fetch or Insert basic Customers
        $customerEmails = array_column($uniqueCustomers, 'email');

        // chunking queries to avoid limits
        $chunks = array_chunk($customerEmails, 1000);
        $customerMap = [];

        foreach ($chunks as $chunk) {
            $selectPlaceholders = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = "SELECT id, email FROM customers WHERE email IN ($selectPlaceholders)";

            $map = MapGenerator::getCustomerMap($manager, $sql, $chunk);
            $customerMap = array_merge($customerMap, $map);
        }

        $customersToInsert = [];
        foreach ($uniqueCustomers as $key => $cust) {
            if (!isset($customerMap[$key])) {
                $customersToInsert[] = $cust['email'];
            }
        }

        if (!empty($customersToInsert)) {
            $insertChunks = array_chunk($customersToInsert, 1000);
            foreach ($insertChunks as $chunk) {
                $insertPlaceholders = implode(', ', array_fill(0, count($chunk), '(?)'));
                $sql = Helpers::buildInsertIgnoreSql(
                    'customers', 
                    ['email'], 
                    'email', 
                    count($chunk)
                );
                $manager->getConnection()->executeStatement($sql, $chunk);
            }

            // Re-fetch to get IDs
            foreach ($insertChunks as $chunk) {
                $selectPlaceholders = implode(', ', array_fill(0, count($chunk), '?'));
                $sql = "SELECT id, email FROM customers WHERE email IN ($selectPlaceholders)";

                $map = MapGenerator::getCustomerMap($manager, $sql, $chunk);
                $customerMap = array_merge($customerMap, $map);
            }
        }

        // 3. Prepare ChanneledCustomers
        $channeledCustomersByPlatform = [];
        foreach ($uniqueChanneledCustomers as $key => $cc) {
            $channeledCustomersByPlatform[] = [
                'channel' => $cc['channel'],
                'platform_id' => $cc['platform_id'],
            ];
        }

        $chunks = array_chunk($channeledCustomersByPlatform, 1000);
        $channeledCustomerMap = [];

        foreach ($chunks as $chunk) {
            $conditions = [];
            $params = [];
            foreach ($chunk as $cc) {
                $conditions[] = "(channel = ? AND platform_id = ?)";
                $params[] = $cc['channel'];
                $params[] = $cc['platform_id'];
            }
            $sql = "SELECT id, channel, platform_id, data FROM channeled_customers WHERE " . implode(' OR ', $conditions);

            $map = MapGenerator::getChanneledCustomerMap($manager, $sql, $params);
            $channeledCustomerMap = array_merge($channeledCustomerMap, $map);
        }

        // Insert vs Update arrays
        $ccToInsert = [];
        $ccToUpdate = [];

        foreach ($uniqueChanneledCustomers as $key => $cc) {
            $customerKey = KeyGenerator::generateCustomerKey($cc['customer_email']);
            // Fallback in case something weird happens
            if (!isset($customerMap[$customerKey])) {
                continue;
            }
            $customerId = $customerMap[$customerKey]['id'];

            if (isset($channeledCustomerMap[$key])) {
                // Determine if data update is necessary
                $existingData = is_string($channeledCustomerMap[$key]['data'])
                                ? json_decode($channeledCustomerMap[$key]['data'], true)
                                : $channeledCustomerMap[$key]['data'];
                if (is_array($existingData)) {
                    $mergedData = $existingData;
                    if (isset($cc['data']['addresses'])) {
                        $mergedData['addresses'] = Helpers::multiDimensionalArrayUnique(
                            array_merge(
                                $mergedData['addresses'] ?? [],
                                $cc['data']['addresses']
                            )
                        );
                    }
                    $cc['data'] = $mergedData;
                }

                $ccToUpdate[] = [
                    'id' => $channeledCustomerMap[$key]['id'],
                    'customer_id' => $customerId,
                    'email' => $cc['customer_email'],
                    'platform_id' => $cc['platform_id'],
                    'channel' => $cc['channel'],
                    'platform_created_at' => $cc['platform_created_at'] instanceof DateTime ? $cc['platform_created_at']->format('Y-m-d H:i:s') : $cc['platform_created_at'],
                    'data' => json_encode($cc['data']),
                ];
            } else {
                $ccToInsert[] = [
                    'customer_id' => $customerId,
                    'email' => $cc['customer_email'],
                    'platform_id' => $cc['platform_id'],
                    'channel' => $cc['channel'],
                    'platform_created_at' => $cc['platform_created_at'] instanceof DateTime ? $cc['platform_created_at']->format('Y-m-d H:i:s') : $cc['platform_created_at'],
                    'data' => json_encode($cc['data']),
                ];
            }
        }

        // Bulk Insert ChanneledCustomers
        if (!empty($ccToInsert)) {
            $insertChunks = array_chunk($ccToInsert, 500);
            foreach ($insertChunks as $chunk) {
                $insertParams = [];
                foreach ($chunk as $row) {
                    $insertParams[] = $row['customer_id'];
                    $insertParams[] = $row['email'];
                    $insertParams[] = $row['platform_id'];
                    $insertParams[] = $row['channel'];
                    $insertParams[] = $row['platform_created_at'];
                    $insertParams[] = $row['data'];
                }

                $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'));
                $manager->getConnection()->executeStatement(
                    "INSERT INTO channeled_customers (customer_id, email, platform_id, channel, platform_created_at, data) VALUES $placeholders",
                    $insertParams
                );
            }
        }

        // Bulk Update ChanneledCustomers (using ON DUPLICATE KEY UPDATE)
        // Since id is primary key, we can do multi-row insert with ON DUPLICATE KEY UPDATE smoothly.
        if (!empty($ccToUpdate)) {
            $updateChunks = array_chunk($ccToUpdate, 500);
            foreach ($updateChunks as $chunk) {
                $updateParams = [];
                foreach ($chunk as $row) {
                    $updateParams[] = $row['id'];
                    $updateParams[] = $row['customer_id'];
                    $updateParams[] = $row['email'];
                    $updateParams[] = $row['platform_id'];
                    $updateParams[] = $row['channel'];
                    $updateParams[] = $row['platform_created_at'];
                    $updateParams[] = $row['data'];
                }

                $sql = Helpers::buildUpsertSql(
                    'channeled_customers', 
                    ['id', 'customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data'], 
                    ['customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data', 'updated_at'], 
                    'id', 
                    count($chunk)
                );
                $manager->getConnection()->executeStatement($sql, $updateParams);
            }
        }

        return [
            'emails' => array_column($uniqueCustomers, 'email'),
            'platform_ids' => array_column($uniqueChanneledCustomers, 'platform_id'),
            'channels' => array_unique(array_column($uniqueChanneledCustomers, 'channel')),
        ];
    }
}
