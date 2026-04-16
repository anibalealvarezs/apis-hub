<?php

namespace Core\Services;

use DateTime;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SyncService
{
    protected ?LoggerInterface $logger = null;

    /**
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Executes the sync process for a given channel.
     *
     * @param string $channel
     * @param string|array|null $startDateOrConfig
     * @param string|null $endDateStr
     * @param array $config
     * @param LoggerInterface|null $logger
     * @param string|null $instanceName
     * @return Response
     * @throws \Throwable
     */
    public function execute(
        string $channel,
        string|array|null $startDateOrConfig = null,
        string|null $endDateStr = null,
        array $config = [],
        ?LoggerInterface $logger = null,
        ?string $instanceName = null
    ): Response {
        if ($logger) {
            $this->logger = $logger;
        } elseif (! $this->logger) {
            $this->logger = Helpers::setLogger("sync-{$channel}.log");
        }

        try {
            $this->logger?->info("DEBUG: SyncService::execute - ENTRY", ['channel' => $channel]);

            $startDateStr = null;
            if (is_array($startDateOrConfig)) {
                $config = $startDateOrConfig;
            } else {
                $startDateStr = $startDateOrConfig;
            }

            // 1. Get official driver via Factory
            $this->logger?->info("DEBUG: SyncService::execute - RESOLVING DRIVER via Factory");
            $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel, $this->logger, $config);
            $this->logger?->info("DEBUG: SyncService::execute - DRIVER RESOLVED", ['class' => get_class($driver)]);

            // 2. Build final configuration
            $validatedConfig = \Classes\DriverInitializer::validateConfig($channel, $this->logger);
            $finalConfig = array_merge($validatedConfig, $config);

            // 3. Inject production dependencies
            $finalConfig['manager'] = Helpers::getManager();
            $this->logger?->info("DEBUG: SyncService::execute - Manager injected. ID: " . spl_object_id($finalConfig['manager']) . " | Open: " . ($finalConfig['manager']->isOpen() ? 'YES' : 'NO'));
            $finalConfig['seeder'] = new \Classes\ProductionEntityMapper($finalConfig['manager']);

            // 4. Date normalization
            $startDate = new DateTime($startDateStr ?? $finalConfig['startDate'] ?? $finalConfig['start_date'] ?? '-30 days');
            $endDate = new DateTime($endDateStr ?? $finalConfig['endDate'] ?? $finalConfig['end_date'] ?? 'now');

            // 5. Logging and Execution
            $sanitizedConfig = $finalConfig;
            array_walk_recursive($sanitizedConfig, function (&$value, $key) {
                if (preg_match('/(secret|token|pass|key)/i', (string)$key)) {
                    $value = '********';
                }
            });

            $this->logger->info("SyncService: Executing sync for channel '{$channel}'", [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'instance' => $instanceName,
                'config' => $sanitizedConfig,
            ]);

            $this->logger?->info("DEBUG: SyncService::execute - INVOKING driver->sync");
            $result = $driver->sync($startDate, $endDate, $finalConfig);
            $this->logger?->info("DEBUG: SyncService::execute - driver->sync RETURNED");

            return new Response(json_encode([
                'success' => true,
                'message' => 'Sync completed successfully',
                'data' => (array)$result
            ]), Response::HTTP_OK, ['Content-Type' => 'application/json']);

        } catch (\Throwable $e) {
            $this->logger->error("SyncService Error [{$channel}]: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
