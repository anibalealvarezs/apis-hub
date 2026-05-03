<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for exposed public data endpoints (Phase 6).
 */
class PublicDataController extends BaseController
{
    /**
     * Entry point for all public data requests.
     */
    public function getResourceData(Request $request, string $channel, string $resource): JsonResponse
    {
        try {
            $channelId = $channel;

            $config = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getChannelConfig($channelId);
            if (empty($config)) {
                return new JsonResponse(['success' => false, 'error' => "Channel $channelId not found"], Response::HTTP_NOT_FOUND);
            }

            $driverClass = $config['driver'];
            if (! class_exists($driverClass)) {
                return new JsonResponse(['success' => false, 'error' => "Driver class for $channelId not found"], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $resources = $driverClass::getPublicResources();
            $table = $resources[$resource] ?? null;

            if (! $table) {
                return new JsonResponse(['success' => false, 'error' => "Resource $resource not supported for channel $channelId"], Response::HTTP_BAD_REQUEST);
            }

            $conn = $this->em->getConnection();
            $data = $conn->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id DESC LIMIT 500");

            return new JsonResponse([
                'success' => true,
                'channel' => $channelId,
                'resource' => $resource,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
