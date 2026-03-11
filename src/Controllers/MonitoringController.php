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
        $chanRaw = $job instanceof Job ? $job->getChannel() : $job['channel'];
        $chan = \Enums\Channel::tryFromName($chanRaw)?->name ?? strtolower($chanRaw);
        $ent = strtolower($job instanceof Job ? $job->getEntity() : $job['entity']);
        
        $payload = $job instanceof Job ? $job->getPayload() : json_decode($job['payload'], true);
        $params = $payload['params'] ?? [];
        $jobStartDate = $params['startDate'] ?? $params['start_date'] ?? null;
        $jobEndDate = $params['endDate'] ?? $params['end_date'] ?? null;

        if ($jobStartDate) $jobStartDate = str_replace('+', ' ', $jobStartDate); // Decode "+ " back to space if any
        if ($jobEndDate) $jobEndDate = str_replace('+', ' ', $jobEndDate);

        // Try to find exact match including dates
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
        
        // Fallback: match without dates
        foreach ($instances as $instance) {
            $instChanRaw = $instance['channel'] ?? '';
            $instChan = \Enums\Channel::tryFromName($instChanRaw)?->name ?? strtolower($instChanRaw);
            $instEnt = strtolower($instance['entity'] ?? '');
            
            if ($chan === $instChan && $ent === $instEnt) {
                return $instance['name'];
            }
        }

        return null;
    }

    public function data(): JsonResponse
    {
        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];
        
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
                'entity' => $instance['entity'],
                'period' => $period,
                'port' => $instance['port'] ?? 8080
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
            if (count($groupedJobs[$targetId]) >= 6) continue;

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

        $entitiesToMonitor = [
            'Metrics' => 'Analytics\Metric', 'Orders' => 'Analytics\Order',
            'Customers' => 'Analytics\Customer', 'Campaigns' => 'Analytics\Campaign',
            'Ads' => 'Analytics\Ad', 'Jobs (Total)' => 'Job'
        ];

        $dbTotals = [];
        foreach ($entitiesToMonitor as $label => $className) {
            try {
                $fullClass = "\\Entities\\" . $className;
                $count = $this->em->createQueryBuilder()->select('count(e.id)')->from($fullClass, 'e')->getQuery()->getSingleScalarResult();
                $dbTotals[] = ['entity' => $label, 'count' => (int)$count];
            } catch (\Exception $e) {
                $dbTotals[] = ['entity' => $label, 'count' => 0];
            }
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
                    $cacheCtrl = new \Controllers\CacheController();
                    $cacheCtrl->fetchData($job->getEntity(), $channel, $payload['params'] ?? null, isset($payload['body']) ? json_encode($payload['body']) : null);
                    
                    $jobRepo->update($id, (object)['status' => JobStatus::completed->value]);
                    return new JsonResponse(['success' => true, 'message' => "Job #$id processed successfully"]);

                case 'cancel':
                    $jobRepo->update($id, (object)['status' => JobStatus::cancelled->value]);
                    return new JsonResponse(['success' => true, 'message' => "Job #$id manually cancelled/deactivated"]);

                default: return new JsonResponse(['error' => "Action '$action' not supported"], 400);
            }
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
