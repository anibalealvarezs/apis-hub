<?php

namespace Core\Services;

use Core\Drivers\DriverFactory;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use DateTime;
use Exception;

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
     * @param string $channel El identificador del canal (ej. 'shopify', 'tiktok')
     * @param string|null $startDateStr Fecha de inicio en formato Y-m-d
     * @param string|null $endDateStr Fecha de fin en formato Y-m-d
     * @param array $config Configuración adicional (jobId, resume, etc.)
     * @return Response
     * @throws Exception
     */
    public function execute(
        string $channel, 
        ?string $startDateStr = null, 
        ?string $endDateStr = null, 
        array $config = []
    ): Response {
        if (!$this->logger) {
            $this->logger = Helpers::setLogger("sync-{$channel}.log");
        }

        try {
            $driver = DriverFactory::get($channel, $this->logger);

            // Normalización de fechas
            $startDate = $startDateStr ? new DateTime($startDateStr) : new DateTime('-30 days');
            $endDate = $endDateStr ? new DateTime($endDateStr) : new DateTime();

            $this->logger->info("SyncService: Executing sync for channel '{$channel}'", [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'config' => $config
            ]);

            return $driver->sync($startDate, $endDate, $config);

        } catch (Exception $e) {
            $this->logger->error("SyncService Error [{$channel}]: " . $e->getMessage());
            throw $e;
        }
    }
}
