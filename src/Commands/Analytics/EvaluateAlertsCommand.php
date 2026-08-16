<?php

namespace Commands\Analytics;

use Controllers\AnalyticsController;
use Helpers\Helpers;
use Services\Analytics\VirtualMetricEngine\AstDataHydrator;
use Services\Analytics\VirtualMetricEngine\AstParser;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:evaluate-alerts',
    description: 'Evaluates threshold-based alerts defined in config/alerts.json and posts results to Facade.'
)]
class EvaluateAlertsCommand extends Command
{
    private \Doctrine\ORM\EntityManager $em;

    public function __construct(?\Doctrine\ORM\EntityManager $em = null)
    {
        $this->em = $em ?? Helpers::getManager();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to alerts.json config file', 'config/alerts.json')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force evaluation regardless of next_evaluation_at schedule')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Evaluate thresholds without sending HTTP callbacks to Facade');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configPath = $input->getOption('config');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!file_exists($configPath)) {
            // Check relative to project root
            $rootPath = dirname(__DIR__, 3) . '/' . $configPath;
            if (file_exists($rootPath)) {
                $configPath = $rootPath;
            } else {
                $output->writeln("<comment>Alerts config file not found ({$configPath}). Nothing to evaluate.</comment>");
                return Command::SUCCESS;
            }
        }

        $content = file_get_contents($configPath);
        $alerts = json_decode($content, true);

        if (!is_array($alerts) || empty($alerts)) {
            $output->writeln("<comment>No active alerts defined in {$configPath}.</comment>");
            return Command::SUCCESS;
        }

        $logger = Helpers::setLogger('alerts.log');
        $logger->info("--- Starting Alert Evaluation Run ---", ['count' => count($alerts), 'force' => $force, 'dryRun' => $dryRun]);

        $facadeUrl = $_ENV['ALERT_FACADE_URL'] ?? null;
        if (!$facadeUrl && !empty($_ENV['MONITOR_FACADE_URL'])) {
            $facadeUrl = rtrim(dirname($_ENV['MONITOR_FACADE_URL']), '/') . '/api/alerts/triggered';
        }
        $monitorToken = $_ENV['MONITOR_TOKEN'] ?? null;

        $nowIso = date('c');
        $nowTs = time();

        foreach ($alerts as $alert) {
            $alertId = $alert['id'] ?? null;
            $alertName = $alert['name'] ?? 'Alert #' . $alertId;
            $nextEval = $alert['next_evaluation_at'] ?? null;

            if (!$force && $nextEval) {
                $nextTs = strtotime($nextEval);
                if ($nextTs > $nowTs) {
                    $output->writeln("  [Skip] Alert '{$alertName}' (ID {$alertId}) not due until {$nextEval}");
                    continue;
                }
            }

            $output->writeln("\n🔔 <info>Evaluating Alert:</info> <comment>{$alertName}</comment> (ID: {$alertId})");

            $astTemplate = $alert['ast'] ?? null;
            if (!$astTemplate) {
                $output->writeln("  <error>Error: Missing AST template for alert {$alertName}</error>");
                continue;
            }

            $lines = $alert['calculation_lines'] ?? [
                ['id' => null, 'label' => 'Default Line', 'asset_filter' => ['asset_platform_id' => 'all']]
            ];

            foreach ($lines as $line) {
                $lineId = $line['id'] ?? null;
                $lineLabel = $line['label'] ?? 'Line #' . $lineId;
                $assetFilter = $line['asset_filter'] ?? [];

                $output->writeln("   - Calculation Line: <comment>{$lineLabel}</comment>");

                try {
                    $ast = $astTemplate;
                    $this->injectAssetFilters($ast, $assetFilter);
                    AnalyticsController::translatePlatformIds($ast, $this->em);

                    $parser = new AstParser();
                    $node = $parser->parse($ast);

                    $filters = $alert['filters'] ?? [];
                    if (!isset($filters['startDate']) && !isset($filters['endDate'])) {
                        // Default to yesterday
                        $filters['startDate'] = date('Y-m-d', strtotime('-1 day'));
                        $filters['endDate'] = date('Y-m-d', strtotime('-1 day'));
                    }

                    $hydrator = new AstDataHydrator($this->em, $logger);
                    $metricData = $hydrator->hydrate($node, $filters);

                    $context = new EvaluationContext($metricData, [], $filters);
                    $resultVal = $node->evaluate($context);

                    // Compute aggregated scalar value
                    $evaluatedValue = $this->aggregateResult($resultVal, $alert['aggregation_method'] ?? 'latest');
                    $output->writeln("     Evaluated Value: <info>{$evaluatedValue}</info>");

                    // Check thresholds
                    $upperLimit = $alert['upper_limit'] !== null ? (float) $alert['upper_limit'] : null;
                    $lowerLimit = $alert['lower_limit'] !== null ? (float) $alert['lower_limit'] : null;

                    $status = 'ok';
                    $thresholdType = null;
                    $thresholdValue = null;

                    if ($upperLimit !== null && $evaluatedValue > $upperLimit) {
                        $status = 'triggered';
                        $thresholdType = 'upper';
                        $thresholdValue = $upperLimit;
                        $output->writeln("     ⚠️ <error>TRIGGERED: Upper limit ({$upperLimit}) exceeded!</error>");
                    } elseif ($lowerLimit !== null && $evaluatedValue < $lowerLimit) {
                        $status = 'triggered';
                        $thresholdType = 'lower';
                        $thresholdValue = $lowerLimit;
                        $output->writeln("     ⚠️ <error>TRIGGERED: Lower limit ({$lowerLimit}) breached!</error>");
                    } else {
                        $output->writeln("     ✅ Status: OK");
                    }

                    $payload = [
                        'alert_id' => $alertId,
                        'calculation_line_id' => $lineId,
                        'alert_name' => $alertName,
                        'source_type' => $alert['source_type'] ?? 'metric',
                        'source_summary' => $alertName,
                        'asset_summary' => $lineLabel,
                        'evaluated_value' => $evaluatedValue,
                        'threshold_type' => $thresholdType,
                        'threshold_value' => $thresholdValue,
                        'status' => $status,
                        'triggered_at' => $nowIso,
                        'evaluation_window' => [
                            'start' => $filters['startDate'] ?? null,
                            'end' => $filters['endDate'] ?? null,
                        ],
                    ];

                    if (!$dryRun && $facadeUrl && $monitorToken) {
                        $this->postResultToFacade($facadeUrl, $monitorToken, $payload);
                    } elseif ($dryRun) {
                        $output->writeln("     [Dry Run] Skipping HTTP callback");
                    }

                } catch (\Throwable $e) {
                    $output->writeln("     <error>Evaluation Error: {$e->getMessage()}</error>");
                    $logger->error("Alert evaluation failed", ['alert_id' => $alertId, 'line_id' => $lineId, 'error' => $e->getMessage()]);

                    if (!$dryRun && $facadeUrl && $monitorToken) {
                        $errorPayload = [
                            'alert_id' => $alertId,
                            'calculation_line_id' => $lineId,
                            'alert_name' => $alertName,
                            'source_type' => $alert['source_type'] ?? 'metric',
                            'source_summary' => $alertName,
                            'asset_summary' => $lineLabel,
                            'status' => 'warning',
                            'warning_message' => $e->getMessage(),
                            'triggered_at' => $nowIso,
                        ];
                        $this->postResultToFacade($facadeUrl, $monitorToken, $errorPayload);
                    }
                }
            }
        }

        $logger->info("--- Alert Evaluation Run Complete ---");
        return Command::SUCCESS;
    }

    /**
     * Recursively inject calculation line asset filters into metric AST nodes.
     */
    protected function injectAssetFilters(array &$node, array $assetFilter): void
    {
        if (isset($node['type'])) {
            if ($node['type'] === 'metric') {
                $node['filters'] = array_merge($node['filters'] ?? [], $assetFilter);
            } elseif ($node['type'] === 'operator') {
                if (isset($node['left']) && is_array($node['left'])) {
                    $this->injectAssetFilters($node['left'], $assetFilter);
                }
                if (isset($node['right']) && is_array($node['right'])) {
                    $this->injectAssetFilters($node['right'], $assetFilter);
                }
            }
        }
    }

    /**
     * Convert series or scalar evaluation result to a single aggregated float.
     */
    protected function aggregateResult(mixed $result, string $method): float
    {
        if (!is_array($result)) {
            return (float) $result;
        }

        if (empty($result)) {
            return 0.0;
        }

        $values = array_map('floatval', array_values($result));

        return match ($method) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            'latest' => end($values),
            default => end($values),
        };
    }

    /**
     * Post evaluation result payload to Facade webhook endpoint.
     */
    protected function postResultToFacade(string $url, string $token, array $payload): void
    {
        $ch = curl_init($url);
        $json = json_encode($payload);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Monitoring-Token: ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \Exception("Facade webhook returned status {$code}: {$response}");
        }
    }
}
