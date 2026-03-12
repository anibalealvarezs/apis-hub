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
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    private function getTargetContainerId($job, array $instances): ?string
    {
        $payload = $job instanceof Job ? $job->getPayload() : json_decode($job['payload'], true);
        
        // 1. Direct match by instance_name (the most reliable)
        if (isset($payload['instance_name'])) {
            return $payload['instance_name'];
        }

        $chanRaw = $job instanceof Job ? $job->getChannel() : $job['channel'];
        $chan = \Enums\Channel::tryFromName($chanRaw)?->name ?? strtolower($chanRaw);
        $ent = strtolower($job instanceof Job ? $job->getEntity() : $job['entity']);
        
        $params = $payload['params'] ?? [];
        $jobStartDate = $params['startDate'] ?? $params['start_date'] ?? null;
        $jobEndDate = $params['endDate'] ?? $params['end_date'] ?? null;

        if ($jobStartDate) $jobStartDate = str_replace('+', ' ', $jobStartDate);
        if ($jobEndDate) $jobEndDate = str_replace('+', ' ', $jobEndDate);

        // 2. Fallback to channel/entity/date match
        foreach ($instances as $instance) {
            $instChanRaw = $instance['channel'] ?? '';
            $instChan = \Enums\Channel::tryFromName($instChanRaw)?->name ?? strtolower($instChanRaw);
            $instEnt = strtolower($instance['entity'] ?? '');
            $instStart = $instance['start_date'] ?? null;
            $instEnd = $instance['end_date'] ?? null;

            if ($chan === $instChan && $ent === $instEnt) {
                if ($instStart === $jobStartDate && $instEnd === $jobEndDate) {
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

        // Fetch jobs for grouping - last 200 to ensure we find some for each container
        $jobRepo = $this->em->getRepository(Job::class);
        $allRecentJobs = $jobRepo->findBy([], ['id' => 'DESC'], 200);

        $groupedJobs = [];
        foreach ($allRecentJobs as $job) {
            $targetId = $this->getTargetContainerId($job, $instances);
            if (!$targetId) continue;

            if (!isset($groupedJobs[$targetId])) $groupedJobs[$targetId] = [];
            if (count($groupedJobs[$targetId]) >= 10) continue; // Show more in detail

            $payload = $job->getPayload() ?? [];
            $params = $payload['params'] ?? [];
            $frequency = 'N/A';
            foreach ($instances as $instance) {
                if ($instance['name'] === $targetId) {
                    $frequency = $instance['frequency'] ?? $frequency;
                    break;
                }
            }

            $groupedJobs[$targetId][] = [
                'id' => $job->getId(),
                'uuid' => $job->getUuid(),
                'channel' => $job->getChannel(),
                'entity' => $job->getEntity(),
                'status' => $job->getStatus(),
                'params' => $params,
                'frequency' => $frequency,
                'created_at' => $job->getCreatedAt() ? $job->getCreatedAt()->format('Y-m-d H:i:s') : 'N/A',
                'updated_at' => $job->getUpdatedAt() ? $job->getUpdatedAt()->format('Y-m-d H:i:s') : 'N/A',
                'message' => $job->getMessage()
            ];
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
            'Pages' => ['class' => 'Analytics\Page', 'channeled' => null],
            'Posts' => ['class' => 'Analytics\Post', 'channeled' => null],
            'Queries' => ['class' => 'Analytics\Query', 'channeled' => null],
            'Countries' => ['class' => 'Analytics\Country', 'channeled' => null],
            'Devices' => ['class' => 'Analytics\Device', 'channeled' => null],
            'Jobs' => ['class' => 'Job', 'channeled' => 'Job']
        ];

        $dbTotals = [];
        $conn = $this->em->getConnection();
        
        foreach ($statsConfig as $label => $config) {
            $entry = ['entity' => $label, 'count' => 0, 'channels' => []];
            
            // 1. Base Class Count (Raw SQL for speed)
            if ($config['class']) {
                try {
                    $tableName = self::getTableNameForEntity($config['class']);
                    if ($tableName) {
                        $count = $conn->fetchOne("SELECT COUNT(*) FROM $tableName");
                        $entry['count'] = (int)$count;
                    }
                } catch (\Exception $e) {}
            }

            // 2. Channeled Breakdown (Raw SQL for speed)
            if ($config['channeled']) {
                try {
                    $tableName = self::getTableNameForEntity($config['channeled']);
                    if ($tableName) {
                        $results = $conn->fetchAllAssociative("SELECT channel, COUNT(*) as count FROM $tableName GROUP BY channel");
                        
                        $channeledTotal = 0;
                        foreach ($results as $res) {
                            $channelId = (int)$res['channel'];
                            $channelCount = (int)$res['count'];
                            $channelName = \Enums\Channel::tryFrom($channelId)?->getCommonName() ?? \Enums\Channel::tryFromName($res['channel'])?->getCommonName() ?? "Ch $channelId";
                            $entry['channels'][] = ['name' => $channelName, 'count' => $channelCount];
                            $channeledTotal += $channelCount;
                        }
                        
                        if (!$config['class']) {
                            $entry['count'] = $channeledTotal;
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
                    $newJobData = [
                        'channel' => $job->getChannel(),
                        'entity' => $job->getEntity(),
                        'payload' => $job->getPayload(),
                        'status' => JobStatus::scheduled->value
                    ];
                    $jobRepo->create((object)$newJobData);
                    return new JsonResponse(['success' => true, 'message' => "New job scheduled. Original #$id history preserved."]);
                
                case 'process':
                    if ($job->getStatus() !== JobStatus::scheduled->value) {
                         return new JsonResponse(['error' => 'Only scheduled jobs can be processed manually'], 400);
                    }
                    if (!$jobRepo->claimJob($id)) return new JsonResponse(['error' => 'Job already processing'], 409);
                    
                    $channel = \Enums\Channel::tryFromName($job->getChannel());
                    $payload = $job->getPayload() ?? [];
                    
                    // Execute in background to avoid blocking the single-threaded web server
                    $params = json_encode($payload['params'] ?? []);
                    $cmd = "php bin/cli.php apis-hub:cache \"{$channel->name}\" \"{$job->getEntity()}\" --params='{$params}' > /dev/null 2>&1 &";
                    exec($cmd);

                    return new JsonResponse(['success' => true, 'message' => "Job #$id triggered in background. Refresh in a few moments."]);

                case 'cancel':
                    $jobRepo->update($id, (object)['status' => JobStatus::cancelled->value]);
                    return new JsonResponse(['success' => true, 'message' => "Job #$id manually cancelled/deactivated"]);

                default: return new JsonResponse(['error' => "Action '$action' not supported"], 400);
            }
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $logFile = $request->query->get('file', 'jobs');
        $lines = (int)$request->query->get('lines', 500);
        
        $filePath = '';
        switch ($logFile) {
            case 'jobs': $filePath = '/app/logs/jobs.log'; break;
            case 'cron': $filePath = '/app/logs/cron.log'; break;
            case 'gsc':  $filePath = '/app/logs/gsc.log';  break;
            default: return new JsonResponse(['error' => 'Invalid log file'], 400);
        }

        if (!file_exists($filePath)) {
            return new JsonResponse(['content' => "Log file not found: $filePath"]);
        }

        $content = $this->readLastLines($filePath, $lines);
        return new JsonResponse(['content' => $content, 'file' => $logFile]);
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
            'gsc' => 'Google Search Console',
            'fb-ads' => 'Facebook',
            'facebook' => 'Facebook',
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
