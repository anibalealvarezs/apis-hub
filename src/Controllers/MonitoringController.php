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

    private function getTargetContainerId($job): ?string
    {
        $chan = strtolower($job instanceof Job ? $job->getChannel() : $job['channel']);
        $ent = strtolower($job instanceof Job ? $job->getEntity() : $job['entity']);
        
        $payload = $job instanceof Job ? $job->getPayload() : json_decode($job['payload'], true);
        $params = $payload['params'] ?? [];
        $startDate = $params['startDate'] ?? $params['start_date'] ?? '';

        // GSC Logic
        if ($chan === 'gsc' || $chan === 'google_search_console' || $chan === '8') {
            if ($ent === 'metric') {
                if (str_starts_with($startDate, '2026-01')) return 'gsc-jan';
                if (str_starts_with($startDate, '2026-02')) return 'gsc-feb';
                return 'gsc-recent';
            }
        } 
        // Facebook Logic
        elseif ($chan === 'facebook' || $chan === 'fb-ads' || $chan === '3') {
            if ($ent === 'metric') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && !str_contains($startDate, 'days') && !str_contains($startDate, 'yesterday')) {
                    return 'fb-ads';
                }
                return 'fb-recent';
            }
        }
        return null;
    }

    public function data(): JsonResponse
    {
        // 1. Containers
        $containersData = [
            ['id' => 'gsc-jan', 'name' => 'GSC January', 'source' => 'gsc', 'entity' => 'metric', 'period' => '2026-01', 'port' => 8081],
            ['id' => 'gsc-feb', 'name' => 'GSC February', 'source' => 'gsc', 'entity' => 'metric', 'period' => '2026-02', 'port' => 8082],
            ['id' => 'fb-ads', 'name' => 'FB Ads Historics', 'source' => 'facebook', 'entity' => 'metric', 'period' => 'Full', 'port' => 8083],
            ['id' => 'gsc-recent', 'name' => 'GSC Recent', 'source' => 'gsc', 'entity' => 'metric', 'period' => 'Rolling', 'port' => 8084],
            ['id' => 'fb-recent', 'name' => 'FB Recent', 'source' => 'facebook', 'entity' => 'metric', 'period' => 'Rolling', 'port' => 8085],
            ['id' => 'redis', 'name' => 'Redis Cache', 'source' => 'global', 'entity' => 'cache', 'period' => 'N/A', 'port' => 6379],
        ];

        $conn = $this->em->getConnection();
        $allJobsSql = "SELECT channel, entity, status, payload FROM jobs";
        $results = $conn->fetchAllAssociative($allJobsSql);
        
        $containerStats = [];
        foreach ($results as $row) {
            $targetId = $this->getTargetContainerId($row);
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

        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];
        
        $groupedJobs = [];
        foreach ($allRecentJobs as $job) {
            $targetId = $this->getTargetContainerId($job);
            if (!$targetId) continue;

            if (!isset($groupedJobs[$targetId])) $groupedJobs[$targetId] = [];
            if (count($groupedJobs[$targetId]) >= 3) continue;

            $payload = $job->getPayload() ?? [];
            $params = $payload['params'] ?? [];
            $frequency = 'N/A';
            foreach ($instances as $instance) {
                if (($instance['channel'] ?? '') === $job->getChannel() && ($instance['entity'] ?? '') === $job->getEntity()) {
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
                'created_at' => $job->getCreatedAt() ? $job->getCreatedAt()->format('Y-m-d H:i:s') : 'N/A'
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
