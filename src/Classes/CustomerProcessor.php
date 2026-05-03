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

        $buffer = [];
        $count = 0;
        foreach ($uniqueCustomers as $key => $cust) {
            if (isset($customerMap[$key])) {
                continue;
            }

            $buffer[] = $cust['email'];
            $count++;

            if ($count >= 1000) {
                $sql = Helpers::buildInsertIgnoreSql('customers', ['email'], 'email', $count);
                $manager->getConnection()->executeStatement($sql, $buffer);
                $buffer = [];
                $count = 0;
            }
        }

        if ($count > 0) {
            $sql = Helpers::buildInsertIgnoreSql('customers', ['email'], 'email', $count);
            $manager->getConnection()->executeStatement($sql, $buffer);
        }

        // Re-fetch EVERYTHNG into the map to ensure we have all IDs (including ignored ones)
        foreach ($chunks as $chunk) {
            $selectPlaceholders = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = "SELECT id, email FROM customers WHERE email IN ($selectPlaceholders)";

            $map = MapGenerator::getCustomerMap($manager, $sql, $chunk);
            $customerMap = array_merge($customerMap, $map);
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

        $inCols = ['customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data'];
        $upCols = ['id', 'customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data'];
        
        $inBuffer = [];
        $upBuffer = [];
        $inCount = 0;
        $upCount = 0;

        foreach ($uniqueChanneledCustomers as $key => $cc) {
            $customerKey = KeyGenerator::generateCustomerKey($cc['customer_email']);
            if (!isset($customerMap[$customerKey])) {
                continue;
            }
            $customerId = $customerMap[$customerKey]['id'];

            if (isset($channeledCustomerMap[$key])) {
                // Determine if data update is necessary
                $existingData = is_string($channeledCustomerMap[$key]['data']) ? json_decode($channeledCustomerMap[$key]['data'], true) : $channeledCustomerMap[$key]['data'];
                if (is_array($existingData)) {
                    $mergedData = $existingData;
                    if (isset($cc['data']['addresses'])) {
                        $mergedData['addresses'] = Helpers::multiDimensionalArrayUnique(array_merge($mergedData['addresses'] ?? [], $cc['data']['addresses']));
                    }
                    $cc['data'] = $mergedData;
                }

                $upBuffer[] = $channeledCustomerMap[$key]['id'];
                $upBuffer[] = $customerId;
                $upBuffer[] = $cc['customer_email'];
                $upBuffer[] = $cc['platform_id'];
                $upBuffer[] = $cc['channel'];
                $upBuffer[] = $cc['platform_created_at'] instanceof DateTime ? $cc['platform_created_at']->format('Y-m-d H:i:s') : $cc['platform_created_at'];
                $upBuffer[] = json_encode($cc['data']);
                $upCount++;

                if ($upCount >= 500) {
                    $sql = Helpers::buildUpsertSql('channeled_customers', $upCols, ['customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data', 'updated_at'], 'id', $upCount);
                    $manager->getConnection()->executeStatement($sql, $upBuffer);
                    $upBuffer = [];
                    $upCount = 0;
                }
            } else {
                $inBuffer[] = $customerId;
                $inBuffer[] = $cc['customer_email'];
                $inBuffer[] = $cc['platform_id'];
                $inBuffer[] = $cc['channel'];
                $inBuffer[] = $cc['platform_created_at'] instanceof DateTime ? $cc['platform_created_at']->format('Y-m-d H:i:s') : $cc['platform_created_at'];
                $inBuffer[] = json_encode($cc['data']);
                $inCount++;

                if ($inCount >= 500) {
                    $sql = "INSERT INTO channeled_customers (" . implode(',', $inCols) . ") VALUES " . implode(',', array_fill(0, $inCount, '(?, ?, ?, ?, ?, ?)'));
                    $manager->getConnection()->executeStatement($sql, $inBuffer);
                    $inBuffer = [];
                    $inCount = 0;
                }
            }
        }

        if ($inCount > 0) {
            $sql = "INSERT INTO channeled_customers (" . implode(',', $inCols) . ") VALUES " . implode(',', array_fill(0, $inCount, '(?, ?, ?, ?, ?, ?)'));
            $manager->getConnection()->executeStatement($sql, $inBuffer);
        }

        if ($upCount > 0) {
            $sql = Helpers::buildUpsertSql('channeled_customers', $upCols, ['customer_id', 'email', 'platform_id', 'channel', 'platform_created_at', 'data', 'updated_at'], 'id', $upCount);
            $manager->getConnection()->executeStatement($sql, $upBuffer);
        }

        return [
            'emails' => array_column($uniqueCustomers, 'email'),
            'platform_ids' => array_column($uniqueChanneledCustomers, 'platform_id'),
            'channels' => array_unique(array_column($uniqueChanneledCustomers, 'channel')),
        ];
    }
}
