<?php

namespace Controllers;

use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class MonitoringController extends BaseController
{
    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/monitoring.html');
        return $this->renderWithEnv($html);
    }

    private function getTargetContainerId($job, array $instances): ?string
    {
        $payload = $job instanceof Job ? $job->getPayload() : json_decode($job['payload'], true);
        if (!$payload) $payload = [];

        // 1. Direct match by instance_name (the most reliable)
        if (isset($payload['instance_name'])) return $payload['instance_name'];

        $chanRaw = $job instanceof Job ? $job->getChannel() : $row['channel'] ?? $job['channel'];
        
        // Canonical Channel Name (always lowercase string)
        $chan = null;
        if (is_numeric($chanRaw)) {
            $chan = strtolower(\Enums\Channel::tryFrom((int)$chanRaw)?->name ?? (string)$chanRaw);
        } else {
            $chan = strtolower(\Enums\Channel::tryFromName((string)$chanRaw)?->name ?? (string)$chanRaw);
        }

        $ent = strtolower(trim($job instanceof Job ? $job->getEntity() : $job['entity']));
        
        $params = $payload['params'] ?? [];
        $jobStartDate = $params['startDate'] ?? $params['start_date'] ?? null;
        $jobEndDate = $params['endDate'] ?? $params['end_date'] ?? null;

        if ($jobStartDate) $jobStartDate = str_replace('+', ' ', (string)$jobStartDate);
        if ($jobEndDate) $jobEndDate = str_replace('+', ' ', (string)$jobEndDate);

        // 2. Fallback to channel/entity/date match
        foreach ($instances as $instance) {
            $instChanRaw = $instance['channel'] ?? '';
            $instChan = strtolower(\Enums\Channel::tryFromName($instChanRaw)?->name ?? $instChanRaw);
            $instEnt = strtolower($instance['entity'] ?? '');
            
            if ($chan === $instChan && $ent === $instEnt) {
                // If the instance has dates defined, they must match exactly
                if (!empty($instance['start_date']) || !empty($instance['end_date'])) {
                     $instStart = $instance['start_date'] ?? null;
                     $instEnd = $instance['end_date'] ?? null;
                     if ($instStart === $jobStartDate && $instEnd === $jobEndDate) {
                         return $instance['name'];
                     }
                } else {
                    // If instance has NO dates (like Entities Sync), any job with same chan/ent matches
                    return $instance['name'];
                }
            }
        }
        
        return null;
    }

    public function data(): JsonResponse
    {
        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];
        
        // 1. Sort instances by dependency
        $instances = $this->sortInstancesByDependency($instances);

        $containersData = [];
        foreach ($instances as $instance) {
            $period = 'Full';
            if (!empty($instance['start_date'])) {
                $period = $instance['start_date'] === '-3 days' ? 'Rolling' : $instance['start_date'];
            }
            $containersData[] = [
                'id' => $instance['name'],
                'name' => ucwords(str_replace('-', ' ', $instance['name'])),
                'source' => $instance['channel'],
                'group' => $instance['group_label'] ?? ucwords($instance['channel']),
                'entity' => $instance['entity'],
                'period' => $period,
                'port' => $instance['port'] ?? 8080,
                'requires' => $instance['requires'] ?? null
            ];
        }

        $containersData[] = ['id' => 'redis', 'name' => 'Redis Cache', 'source' => 'global', 'entity' => 'cache', 'period' => 'N/A', 'port' => 6379];

        $conn = $this->em->getConnection();
        $allJobsSql = "SELECT channel, entity, status, payload FROM jobs";
        $results = $conn->fetchAllAssociative($allJobsSql);
        
        $containerStats = [];
        foreach ($results as $row) {
            $targetId = $this->getTargetContainerId($row, $instances);
            if ($targetId) {
                $statusName = JobStatus::tryFrom((int)$row['status'])?->name ?? 'unknown';
                if (!isset($containerStats[$targetId])) $containerStats[$targetId] = ['total' => 0];
                $containerStats[$targetId]['total']++;
                $containerStats[$targetId][$statusName] = ($containerStats[$targetId][$statusName] ?? 0) + 1;
            }
        }

        $containers = array_map(function($c) use ($containerStats) {
            $c['stats'] = $containerStats[$c['id']] ?? ['total' => 0];
            return $c;
        }, $containersData);

        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);

        // Fetch ONLY pending, processing, or failed jobs for priority visibility
        $allRecentJobs = $jobRepo->findBy([], ['id' => 'DESC'], 300);
        $priorityJobs = $jobRepo->findBy(
            ['status' => [JobStatus::scheduled->value, JobStatus::processing->value, JobStatus::failed->value]],
            ['id' => 'DESC'],
            100
        );

        $finalJobs = [];
        foreach (array_merge($allRecentJobs, $priorityJobs) as $j) {
            $finalJobs[$j->getId()] = $j;
        }
        krsort($finalJobs);
        $allRecentJobs = array_values($finalJobs);

        $pipelines = [];
        foreach ($allRecentJobs as $job) {
            $targetId = $this->getTargetContainerId($job, $instances) ?: 'global-infrastructure';
            $payload = $job->getPayload() ?? [];
            $params = $payload['params'] ?? [];
            
            $normChan = strtolower(trim($job->getChannel()));
            $normEnt = strtolower(trim($job->getEntity()));

            $pipelineKey = "{$targetId}:{$normChan}:{$normEnt}";

            if (!isset($pipelines[$pipelineKey])) {
                $statusText = JobStatus::tryFrom($job->getStatus())?->name ?? 'unknown';
                $frequency = 'N/A';
                $instanceLabel = ucwords(str_replace(['-', '_'], ' ', $targetId));

                foreach ($instances as $instance) {
                    if ($instance['name'] === $targetId) {
                        $frequency = $instance['frequency'] ?? $frequency;
                        break;
                    }
                }

                $createdAt = $job->getCreatedAt();
                $updatedAt = $job->getUpdatedAt();
                $interval = $createdAt && $updatedAt ? $createdAt->diff($updatedAt) : null;
                $executionTime = 'N/A';
                if ($interval && ($job->getStatus() === JobStatus::completed->value || $job->getStatus() === JobStatus::failed->value)) {
                    $parts = [];
                    if ($interval->h > 0) $parts[] = $interval->h . 'h';
                    if ($interval->i > 0) $parts[] = $interval->i . 'm';
                    if ($interval->s > 0) $parts[] = $interval->s . 's';
                    $executionTime = !empty($parts) ? implode(' ', $parts) : '0s';
                }

                $pipelines[$pipelineKey] = [
                    'id' => $job->getId(),
                    'uuid' => $job->getUuid(),
                    'channel' => strtolower($job->getChannel()),
                    'entity' => strtolower($job->getEntity()),
                    'status' => $job->getStatus(),
                    'status_text' => strtoupper($statusText),
                    'params' => $params,
                    'frequency' => $payload['cron'] ?? $frequency,
                    'execution_time' => $executionTime,
                    'created_at' => $createdAt ? $createdAt->format('Y-m-d H:i:s') : 'N/A',
                    'updated_at' => $updatedAt ? $updatedAt->format('Y-m-d H:i:s') : 'N/A',
                    'message' => $job->getMessage(),
                    'group' => $targetId,
                    'instance_label' => $instanceLabel,
                    'history' => []
                ];
            } else {
                if (count($pipelines[$pipelineKey]['history']) < 10) {
                    $pipelines[$pipelineKey]['history'][] = [
                        'status' => $job->getStatus(),
                        'date' => $job->getUpdatedAt() ? $job->getUpdatedAt()->format('Y-m-d H:i:s') : 'N/A',
                        'message' => $job->getMessage() ?: 'No details'
                    ];
                }
            }
        }

        $groupedJobs = [];
        foreach ($pipelines as $pipeline) {
            $chan = $pipeline['channel'];
            if (!isset($groupedJobs[$chan])) $groupedJobs[$chan] = [];
            $groupedJobs[$chan][] = $pipeline;
        }

        $instanceOrder = array_flip(array_column($instances, 'name'));
        foreach ($groupedJobs as $chan => &$jobs) {
            usort($jobs, function($a, $b) use ($instanceOrder) {
                $idxA = $instanceOrder[$a['group']] ?? 999;
                $idxB = $instanceOrder[$b['group']] ?? 999;
                if ($idxA !== $idxB) return $idxA - $idxB;
                return $b['id'] - $a['id'];
            });
        }

        $statsConfig = [
            'Accounts' => ['class' => 'Analytics\Account', 'channeled' => 'Analytics\Channeled\ChanneledAccount'],
            'Metrics' => ['class' => 'Analytics\Metric', 'channeled' => 'Analytics\Channeled\ChanneledMetric'],
            'Campaigns' => ['class' => 'Analytics\Campaign', 'channeled' => 'Analytics\Channeled\ChanneledCampaign'],
            'Ad Groups' => ['class' => null, 'channeled' => 'Analytics\Channeled\ChanneledAdGroup'],
            'Ads' => ['class' => null, 'channeled' => 'Analytics\Channeled\ChanneledAd'],
            'Creatives' => ['class' => 'Analytics\Creative', 'channeled' => null],
            'Orders' => ['class' => 'Analytics\Order', 'channeled' => 'Analytics\Channeled\ChanneledOrder'],
            'Customers' => ['class' => 'Analytics\Customer', 'channeled' => 'Analytics\Channeled\ChanneledCustomer'],
            'Products' => ['class' => 'Analytics\Product', 'channeled' => 'Analytics\Channeled\ChanneledProduct'],
            'Variants' => ['class' => 'Analytics\ProductVariant', 'channeled' => 'Analytics\Channeled\ChanneledProductVariant'],
            'Categories' => ['class' => 'Analytics\ProductCategory', 'channeled' => 'Analytics\Channeled\ChanneledProductCategory'],
            'Discounts' => ['class' => 'Analytics\Discount', 'channeled' => 'Analytics\Channeled\ChanneledDiscount'],
            'Price Rules' => ['class' => 'Analytics\PriceRule', 'channeled' => 'Analytics\Channeled\ChanneledPriceRule'],
            'Vendors' => ['class' => 'Analytics\Vendor', 'channeled' => 'Analytics\Channeled\ChanneledVendor'],
            'Pages' => ['class' => 'Analytics\Page', 'channeled' => 'Analytics\Page'],
            'Posts' => ['class' => 'Analytics\Post', 'channeled' => 'Analytics\Post'],
            'Queries' => ['class' => 'Analytics\Query', 'channeled' => null],
            'Countries' => ['class' => 'Analytics\Country', 'channeled' => null],
            'Devices' => ['class' => 'Analytics\Device', 'channeled' => null],
            'Jobs' => ['class' => 'Job', 'channeled' => 'Job']
        ];

        $dbTotals = [];
        foreach ($statsConfig as $label => $config) {
            $entry = ['entity' => $label, 'count' => 0, 'channels' => []];
            
            if ($config['class']) {
                try {
                    $tableName = self::getTableNameForEntity($config['class']);
                    if ($tableName) {
                        $entry['count'] = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tableName");
                    }
                } catch (\Exception $e) {}
            }

            if ($config['channeled']) {
                try {
                    $tableName = self::getTableNameForEntity($config['channeled']);
                    if ($tableName) {
                        $sql = "";
                        if ($tableName === 'channeled_accounts') {
                            $sql = "SELECT channel, type, COUNT(*) as count FROM channeled_accounts GROUP BY channel, type";
                        } elseif ($tableName === 'channeled_metrics') {
                            $sql = "SELECT cm.channel, COALESCE(ca1.type, ca2.type, ca3.type, ca4.type) as type, COUNT(*) as count 
                                  FROM channeled_metrics cm 
                                  LEFT JOIN channeled_accounts ca1 ON cm.platform_id = ca1.platform_id AND cm.channel = ca1.channel
                                  LEFT JOIN channeled_ads cad ON cm.platform_id = cad.platform_id AND cm.channel = cad.channel
                                  LEFT JOIN channeled_accounts ca2 ON cad.channeled_account_id = ca2.id
                                  LEFT JOIN channeled_ad_groups cg ON cm.platform_id = cg.platform_id AND cm.channel = cg.channel
                                  LEFT JOIN channeled_accounts ca3 ON cg.channeled_account_id = ca3.id
                                  LEFT JOIN channeled_campaigns cc ON cm.platform_id = cc.platform_id AND cm.channel = cc.channel
                                  LEFT JOIN channeled_accounts ca4 ON cc.channeled_account_id = ca4.id
                                  GROUP BY cm.channel, type";
                        } elseif ($tableName === 'posts') {
                            $sql = "SELECT sub.channel, sub.type, COUNT(*) as count FROM (
                                      SELECT COALESCE(ca1.channel, ca2.channel, ca3.channel) as channel, 
                                             COALESCE(ca1.type, ca2.type, ca3.type) as type
                                      FROM posts p
                                      LEFT JOIN channeled_accounts ca1 ON p.channeled_account_id = ca1.id
                                      LEFT JOIN pages pg ON p.page_id = pg.id
                                      LEFT JOIN channeled_accounts ca2 ON pg.platform_id = ca2.platform_id
                                      LEFT JOIN accounts a ON p.account_id = a.id
                                      LEFT JOIN channeled_accounts ca3 ON ca3.account_id = a.id
                                    ) sub
                                    GROUP BY sub.channel, sub.type";
                        } elseif ($tableName === 'pages') {
                            $sql = "SELECT ca.channel, ca.type, COUNT(*) as count 
                                  FROM pages p 
                                  LEFT JOIN channeled_accounts ca ON p.platform_id = ca.platform_id 
                                  GROUP BY ca.channel, ca.type";
                        } elseif (in_array($tableName, ['channeled_campaigns', 'channeled_ad_groups', 'channeled_ads'])) {
                            $sql = "SELECT e.channel, ca.type, COUNT(*) as count 
                                  FROM $tableName e 
                                  LEFT JOIN channeled_accounts ca ON e.channeled_account_id = ca.id 
                                  GROUP BY e.channel, ca.type";
                        } else {
                            $columns = $conn->fetchFirstColumn("DESCRIBE $tableName");
                            if (in_array('channel', $columns)) {
                                $sql = "SELECT channel, NULL as type, COUNT(*) as count FROM $tableName GROUP BY channel";
                            }
                        }

                        if ($sql) {
                            $resItems = [];
                            try {
                                $results = $conn->fetchAllAssociative($sql);
                                foreach ($results as $res) {
                                    $channelId = (int)($res['channel'] ?? 0);
                                    $channelLabel = $channelId ? (\Enums\Channel::tryFrom($channelId)?->getCommonName() ?? "Ch $channelId") : "Unidentified Channel";
                                    $typeValue = $res['type'] ?? '';
                                    $typeName = $typeValue;
                                    if ($typeValue && class_exists('\Enums\Account')) {
                                        $enum = \Enums\Account::tryFrom($typeValue);
                                        $typeName = $enum ? ucwords(str_replace('_', ' ', $enum->value)) : $typeValue;
                                    }
                                    
                                    $label = $channelLabel . ($typeName ? " • $typeName" : "");
                                    $resItems[] = [
                                        'name' => $label,
                                        'count' => (int)$res['count'],
                                        'channel' => $channelLabel,
                                        'type' => $typeName,
                                        'type_raw' => $typeValue
                                    ];
                                }
                            } catch (\Exception $e) {
                                try {
                                    $results = $conn->fetchAllAssociative("SELECT channel, COUNT(*) as count FROM $tableName GROUP BY channel");
                                    foreach ($results as $res) {
                                        $resItems[] = [
                                            'name' => \Enums\Channel::tryFrom((int)$res['channel'])?->getCommonName() ?? "Channel " . $res['channel'],
                                            'count' => (int)$res['count']
                                        ];
                                    }
                                } catch (\Exception $ex) {}
                            }
                            $entry['channels'] = $resItems;
                            if (!$config['class']) {
                                $entry['count'] = array_sum(array_column($resItems, 'count'));
                            }
                        }
                    }
                } catch (\Exception $e) {}
            }
            $dbTotals[] = $entry;
        }

        return new JsonResponse([
            'containers' => $containers,
            'groupedJobs' => $groupedJobs,
            'dbTotals' => $dbTotals,
            'projectName' => $this->config['project'] ?? 'APIs Hub',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    public function jobAction(Request $request): JsonResponse
    {
        $id = $request->request->get('id');
        $action = $request->request->get('action');

        if (!$id || !$action) {
            $content = json_decode($request->getContent(), true);
            $id = $content['id'] ?? null;
            $action = $content['action'] ?? null;
        }

        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);
        $job = $jobRepo->find($id);

        if (!$job) return new JsonResponse(['error' => 'Job not found'], 404);

        try {
            switch ($action) {
                case 'retry':
                    if ($job->getStatus() === JobStatus::processing->value) {
                        return new JsonResponse(['error' => 'Cannot re-schedule a job that is already processing to avoid overlap.'], 400);
                    }
                    
                    $payload = $job->getPayload() ?? [];
                    $resumeStr = $request->request->get('resume');
                    if (!$resumeStr && isset($content['resume'])) {
                        $resumeStr = $content['resume'];
                    }

                    if ($resumeStr !== null) {
                        if (!isset($payload['params'])) {
                            $payload['params'] = [];
                        }
                        $payload['params']['resume'] = filter_var($resumeStr, FILTER_VALIDATE_BOOLEAN);
                    }

                    $bypassDependencyStr = $request->request->get('bypass_dependency') ?? $content['bypass_dependency'] ?? null;
                    if ($bypassDependencyStr !== null && filter_var($bypassDependencyStr, FILTER_VALIDATE_BOOLEAN)) {
                        unset($payload['params']['requires']);
                    }

                    $newJobData = [
                        'channel' => strtolower($job->getChannel()),
                        'entity' => strtolower($job->getEntity()),
                        'payload' => $payload,
                        'status' => JobStatus::scheduled->value
                    ];
                    $jobRepo->create((object)$newJobData);
                    return new JsonResponse(['success' => true, 'message' => "New job scheduled. Original #$id history preserved."]);
                
                case 'process':
                    if ($job->getStatus() !== JobStatus::scheduled->value && $job->getStatus() !== \Enums\JobStatus::delayed->value) {
                         return new JsonResponse(['error' => 'Only scheduled or delayed jobs can be processed manually'], 400);
                    }
                    
                    // Execute in background WITHOUT claiming here. 
                    // ProcessJobsCommand will claim it when it actually starts.
                    $rootPath = realpath(__DIR__ . '/../../');
                    $cmd = "php {$rootPath}/bin/cli.php app:process-jobs --job-id={$id} >> {$rootPath}/logs/jobs.log 2>&1 &";
                    exec($cmd);

                    return new JsonResponse(['success' => true, 'message' => "Job #$id triggered in background. Once it starts, status will change to 'Processing'."]);

                case 'cancel':
                    $job->addStatus(JobStatus::cancelled->value);
                    $job->addMessage('Manually cancelled via Monitoring Dashboard');
                    $this->em->flush();
                    return new JsonResponse(['success' => true, 'message' => "Job #$id manually cancelled/deactivated"]);

                default: return new JsonResponse(['error' => "Action '$action' not supported"], 400);
            }
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $logFile = $request->query->get('file', 'jobs.log');
        $lines = (int)$request->query->get('lines', 500);
        
        // Security: Prevent directory traversal
        $logFile = basename($logFile);
        if (!str_ends_with($logFile, '.log')) {
             $logFile .= '.log';
        }

        $logDir = realpath(__DIR__ . '/../../logs/');
        $filePath = $logDir . DIRECTORY_SEPARATOR . $logFile;

        // Security: Ensure the file is within the logs directory
        if (strpos($filePath, $logDir) !== 0) {
            return new JsonResponse(['error' => 'Unauthorized access'], 403);
        }

        if (!file_exists($filePath)) {
            return new JsonResponse(['content' => "Log file not found: $filePath"]);
        }

        $content = $this->readLastLines($filePath, $lines);
        return new JsonResponse(['content' => $content, 'file' => $logFile]);
    }

    public function logList(): JsonResponse
    {
        $logDir = realpath(__DIR__ . '/../../logs/');
        $files = glob($logDir . '/*.log');
        
        $logList = array_map(function($f) {
            return [
                'name' => basename($f),
                'size' => round(filesize($f) / 1024, 2) . ' KB',
                'modified' => date('Y-m-d H:i:s', filemtime($f))
            ];
        }, $files);

        // Sort by modification time (newest first)
        usort($logList, function($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });

        return new JsonResponse(['logs' => $logList]);
    }

    private function readLastLines(string $filename, int $lines): string
    {
        if (!file_exists($filename) || !is_readable($filename)) {
            return "File not found or not readable.";
        }

        $file = fopen($filename, "rb");
        if (!$file) return "Could not open file.";

        $lineCount = 0;
        $pos = -2; // Start from end of file
        $t = " ";
        $data = "";

        fseek($file, $pos, SEEK_END);

        while ($lineCount < $lines) {
            try {
                if (fseek($file, $pos, SEEK_END) == -1) break;
                $t = fgetc($file);
                if ($t == "\n") $lineCount++;
                $pos--;
            } catch (\Exception $e) {
                break;
            }
        }

        $data = fread($file, abs($pos));
        fclose($file);

        return $data ?: "Empty log file.";
    }

    private function sortInstancesByDependency(array $instances): array
    {
        // 1. Define channel mapping for grouping
        $channelMap = [
            'gsc' => 'GoogleSearchConsole',
            'google_search_console' => 'GoogleSearchConsole',
            'google-search-console' => 'GoogleSearchConsole',
            'fb-ads' => 'FacebookMarketing',
            'facebook' => 'FacebookOrganic',
            'facebook-ads' => 'FacebookMarketing',
            'facebook_marketing' => 'FacebookMarketing',
            'facebook_organic' => 'FacebookOrganic',
            'fb-organic' => 'FacebookOrganic',
        ];

        // 2. Group instances by mapped channel
        $grouped = [];
        foreach ($instances as $instance) {
            $rawChan = $instance['channel'] ?? 'Other';
            $groupKey = $channelMap[$rawChan] ?? ucwords($rawChan);
            $grouped[$groupKey][] = $instance;
        }

        $allSorted = [];
        foreach ($grouped as $groupName => $chanInstances) {
            $temp = $chanInstances;
            $sorted = [];
            
            while (count($temp) > 0) {
                $addedThisRound = false;
                foreach ($temp as $key => $instance) {
                    $requires = $instance['requires'] ?? null;
                    
                    // Check if the requirement is still in the unsorted list of THIS group
                    // or in ANY OTHER group that hasn't been processed? 
                    // Actually, cross-channel dependencies are rare, but we should check all unsorted.
                    $allUnsortedNames = array_map(fn($i) => $i['name'], $temp);
                    
                    if (!$requires || !in_array($requires, $allUnsortedNames)) {
                        $instance['group_label'] = $groupName; // Cache the group label
                        $sorted[] = $instance;
                        unset($temp[$key]);
                        $addedThisRound = true;
                    }
                }
                
                if (!$addedThisRound) {
                    foreach ($temp as $instance) {
                        $instance['group_label'] = $groupName;
                        $sorted[] = $instance;
                    }
                    break;
                }
            }
            $allSorted = array_merge($allSorted, $sorted);
        }
        
        return $allSorted;
    }

    private static function getTableNameForEntity(string $entityPath): ?string
    {
        $map = [
            'Analytics\Account' => 'accounts',
            'Analytics\Metric' => 'metrics',
            'Analytics\Campaign' => 'campaigns',
            'Analytics\Creative' => 'creatives',
            'Analytics\Order' => 'orders',
            'Analytics\Customer' => 'customers',
            'Analytics\Product' => 'products',
            'Analytics\ProductVariant' => 'product_variants',
            'Analytics\ProductCategory' => 'product_categories',
            'Analytics\Discount' => 'discounts',
            'Analytics\PriceRule' => 'price_rules',
            'Analytics\Vendor' => 'vendors',
            'Analytics\Page' => 'pages',
            'Analytics\Post' => 'posts',
            'Analytics\Query' => 'queries',
            'Analytics\Country' => 'countries',
            'Analytics\Device' => 'devices',
            'Job' => 'jobs',
            'Analytics\Channeled\ChanneledAccount' => 'channeled_accounts',
            'Analytics\Channeled\ChanneledMetric' => 'channeled_metrics',
            'Analytics\Channeled\ChanneledCampaign' => 'channeled_campaigns',
            'Analytics\Channeled\ChanneledAdGroup' => 'channeled_ad_groups',
            'Analytics\Channeled\ChanneledAd' => 'channeled_ads',
            'Analytics\Channeled\ChanneledOrder' => 'channeled_orders',
            'Analytics\Channeled\ChanneledCustomer' => 'channeled_customers',
            'Analytics\Channeled\ChanneledProduct' => 'channeled_products',
            'Analytics\Channeled\ChanneledProductVariant' => 'channeled_product_variants',
            'Analytics\Channeled\ChanneledProductCategory' => 'channeled_product_categories',
            'Analytics\Channeled\ChanneledDiscount' => 'channeled_discounts',
            'Analytics\Channeled\ChanneledPriceRule' => 'channeled_price_rules',
            'Analytics\Channeled\ChanneledVendor' => 'channeled_vendors',
        ];
        return $map[$entityPath] ?? null;
    }
}
