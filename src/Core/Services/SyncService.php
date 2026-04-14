<?php

namespace Core\Services;

use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use DateTime;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class SyncService
{
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Ejecuta la sincronización para cualquier canal registrado.
     *
     * @param string $channel El identificador del canal
     * @param array $config Configuración adicional (jobId, resume, etc.)
     * @param LoggerInterface|null $logger Logger instance
     * @param string|null $instanceName Name of the instance running the sync
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
            $startDateStr = null;
            // SMART DETECT: If 2nd param is array, it's the NEW signature: execute(channel, config, logger, instance)
            if (is_array($startDateOrConfig)) {
                $config = $startDateOrConfig;
                // If logger was passed in 3rd param (instanceof LoggerInterface), use it
                // Note: parameters might be shifted
            } else {
                // OLD signature: execute(channel, startDate, endDate, config)
                $startDateStr = $startDateOrConfig;
            }

            $driverClass = \Anibalealvarezs\ApiDriverCore\Classes\DriverInitializer::getDriverClass($channel);
            $validatedConfig = \Classes\DriverInitializer::validateConfig($channel, $this->logger);
            $finalConfig = array_merge($validatedConfig, $config);

            $driver = DriverFactory::get($channel, $this->logger, $finalConfig);
            $this->logger?->info("DEBUG: SyncService::execute - DRIVER RESOLVED", ['class' => $driverClass]);

            if (isset($finalConfig['processor']) && method_exists($driver, 'setDataProcessor')) {
                $driver->{"setDataProcessor"}($finalConfig['processor']);
            }

            // Date normalization (works for both patterns)
            $startDate = new DateTime($startDateStr ?? $finalConfig['startDate'] ?? $finalConfig['start_date'] ?? '-30 days');
            $endDate = new DateTime($endDateStr ?? $finalConfig['endDate'] ?? $finalConfig['end_date'] ?? 'now');

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

            // Inject production dependencies
            $finalConfig['manager'] = Helpers::getManager();
            $finalConfig['seeder'] = new \Classes\ProductionEntityMapper($finalConfig['manager']);

            $this->logger?->info("DEBUG: SyncService::execute - INVOKING driver->sync");
            $result = $driver->sync($startDate, $endDate, $finalConfig);
            $this->logger?->info("DEBUG: SyncService::execute - driver->sync RETURNED");

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error("SyncService Error [{$channel}]: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
