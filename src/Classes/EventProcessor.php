<?php

declare(strict_types=1);

namespace Classes;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Entities\Analytics\Event;
use Entities\Analytics\Channeled\ChanneledEvent;

class EventProcessor
{
    private static function getLogger(): \Psr\Log\LoggerInterface
    {
        return Helpers::setLogger('event-processor.log');
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @param EntityManager $manager
     * @return void
     */
    public static function processEvents(ArrayCollection $channeledCollection, EntityManager $manager): void
    {
        if ($channeledCollection->isEmpty()) {
            return;
        }

        $conn = $manager->getConnection();
        $eventsData = $channeledCollection->toArray();

        // 1. Bulk Insert/Update global events
        $eventNames = [];
        foreach ($eventsData as $e) {
            if (!is_object($e) || empty($e->name)) continue;
            $eventNames[] = $e->name;
        }
        $eventNames = array_unique($eventNames);

        if (!empty($eventNames)) {
            $cols = ['name', 'created_at', 'updated_at'];
            $now = date('Y-m-d H:i:s');
            
            // Insert ignore global events
            foreach (array_chunk($eventNames, 5000) as $chunk) {
                $params = [];
                foreach ($chunk as $name) {
                    $params[] = $name;
                    $params[] = $now;
                    $params[] = $now;
                }
                
                $sql = Helpers::buildInsertIgnoreSql(
                    'events',
                    $cols,
                    ['name'],
                    count($chunk)
                );
                
                if ($sql) {
                    $conn->executeStatement($sql, $params);
                }
            }
            self::getLogger()->info("Processed " . count($eventNames) . " global events");
        }

        // 2. Fetch global event IDs map
        $eventMap = [];
        if (!empty($eventNames)) {
            foreach (array_chunk($eventNames, 1000) as $chunk) {
                $sqlMap = 'SELECT id, name FROM events WHERE name IN ('
                    . implode(', ', array_fill(0, count($chunk), '?')) . ')';
                $fetched = $conn->executeQuery($sqlMap, $chunk)->fetchAllAssociative();
                foreach ($fetched as $row) {
                    $eventMap[$row['name']] = $row['id'];
                }
            }
        }

        // 3. Fetch channeled account IDs map
        $caPlatformIds = array_unique(array_filter(array_column($eventsData, 'channeledAccountId')));
        $caMap = [];
        if (!empty($caPlatformIds)) {
            $sql = 'SELECT platform_id, id FROM channeled_accounts WHERE platform_id IN ('
                . implode(', ', array_fill(0, count($caPlatformIds), '?')) . ')';
            $fetched = $conn->executeQuery($sql, array_values($caPlatformIds))->fetchAllAssociative();
            foreach ($fetched as $row) {
                $caMap[$row['platform_id']] = $row['id'];
            }
        }

        // 4. Bulk Insert/Update Channeled Events
        $validChanneledEvents = [];
        foreach ($eventsData as $e) {
            if (!is_object($e) || empty($e->name) || empty($e->platformId)) continue;
            
            $globalEventId = $eventMap[$e->name] ?? null;
            $caId = $caMap[$e->channeledAccountId] ?? null;

            if ($globalEventId && $caId) {
                $validChanneledEvents[] = [
                    'platform_id' => $e->platformId,
                    'name' => $e->name,
                    'event_id' => $globalEventId,
                    'channeled_account_id' => $caId,
                    'type' => $e->type ?? 'event',
                    'channel' => $e->channel ?? 0,
                    'data' => !empty($e->data) ? json_encode($e->data) : null,
                    'created_at' => $now ?? date('Y-m-d H:i:s'),
                    'updated_at' => $now ?? date('Y-m-d H:i:s')
                ];
            }
        }

        if (!empty($validChanneledEvents)) {
            $cols = ['platform_id', 'name', 'event_id', 'channeled_account_id', 'type', 'channel', 'data', 'created_at', 'updated_at'];
            
            foreach (array_chunk($validChanneledEvents, 3000) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $params[] = $row['platform_id'];
                    $params[] = $row['name'];
                    $params[] = $row['event_id'];
                    $params[] = $row['channeled_account_id'];
                    $params[] = $row['type'];
                    $params[] = $row['channel'];
                    $params[] = $row['data'];
                    $params[] = $row['created_at'];
                    $params[] = $row['updated_at'];
                }
                
                $sql = Helpers::buildUpsertSql(
                    'channeled_events',
                    $cols,
                    ['name', 'type', 'data', 'updated_at'],
                    'platform_id',
                    count($chunk)
                );
                
                $conn->executeStatement($sql, $params);
            }
            self::getLogger()->info("Processed " . count($validChanneledEvents) . " channeled events");
        }
    }
}
