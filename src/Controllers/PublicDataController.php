<?php

namespace Controllers;

use Helpers\Helpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for exposed public data endpoints (Phase 6).
 */
class PublicDataController extends BaseController
{
    /**
     * Get aggregate data for a specific channel and resource.
     */
    public function getFacebookCampaigns(Request $request): JsonResponse
    {
        return $this->getResourceData($request, 'facebook_marketing', 'campaigns');
    }

    /**
     * Get raw metrics for a specific channel.
     */
    public function getMetrics(Request $request, string $channel): JsonResponse
    {
        // For backward compatibility with 'facebook', 'google', 'gsc' strings
        $channelId = match(strtolower($channel)) {
            'facebook' => 'facebook_marketing',
            'google', 'gsc' => 'google_search_console',
            default => $channel
        };

        return $this->getResourceData($request, $channelId, 'metrics');
    }

    /**
     * Generic resource data retriever via drivers
     */
    private function getResourceData(Request $request, string $channel, string $resource): JsonResponse
    {
        try {
            $config = \Core\Drivers\DriverFactory::getChannelConfig($channel);
            if (empty($config)) {
                return new JsonResponse(['success' => false, 'error' => "Channel $channel not found"], Response::HTTP_NOT_FOUND);
            }

            $driverClass = $config['driver'];
            if (!class_exists($driverClass)) {
                return new JsonResponse(['success' => false, 'error' => "Driver class for $channel not found"], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $resources = $driverClass::getPublicResources();
            $table = $resources[$resource] ?? null;

            if (!$table) {
                return new JsonResponse(['success' => false, 'error' => "Resource $resource not supported for channel $channel"], Response::HTTP_BAD_REQUEST);
            }

            $conn = $this->em->getConnection();
            $data = $conn->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id DESC LIMIT 500");
            
            return new JsonResponse([
                'success' => true,
                'channel' => $channel,
                'resource' => $resource,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
