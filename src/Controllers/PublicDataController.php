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
     * Get aggregate Facebook campaign data.
     */
    public function getFacebookCampaigns(Request $request): JsonResponse
    {
        try {
            $conn = $this->em->getConnection();
            $data = $conn->fetchAllAssociative("SELECT * FROM fb_campaigns ORDER BY id DESC LIMIT 100");
            
            return new JsonResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get raw metrics for a specific channel.
     */
    public function getMetrics(Request $request, string $channel): JsonResponse
    {
        try {
            $conn = $this->em->getConnection();
            $table = match(strtolower($channel)) {
                'facebook' => 'fb_metrics',
                'google', 'gsc' => 'gsc_metrics',
                default => null
            };

            if (!$table) {
                return new JsonResponse(['success' => false, 'error' => 'Unsupported channel'], Response::HTTP_BAD_REQUEST);
            }

            $data = $conn->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id DESC LIMIT 500");
            
            return new JsonResponse([
                'success' => true,
                'channel' => $channel,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
