<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Services\Analytics\VirtualMetricEngine\AstParser;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;
use Exception;

class AnalyticsController extends BaseController
{
    /**
     * Compute a Custom KPI via AST Formula from the Facade.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function computeKpi(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true);

            if (!isset($payload['ast'])) {
                return $this->errorResponse('Missing AST payload.', 400);
            }

            // Note: In Phase 3, we will dynamically fetch these from the DB using AggregationPlanner.
            // For now, this is the scaffolding structure for the request flow.
            $metricData = $payload['metricData'] ?? []; 
            
            $parser = new AstParser();
            $node = $parser->parse($payload['ast']);
            
            $context = new EvaluationContext($metricData);
            $result = $node->evaluate($context);

            // Forward to Python Analytics Engine if requested
            if (isset($payload['calculate_regression']) && $payload['calculate_regression']) {
                $apiKey = $payload['admin_api_key'] ?? null;
                $result = $this->forwardToPythonEngine($result, '/api/v1/stats/regression', $apiKey);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Internal method to forward complex math to the Python FastAPI container.
     */
    protected function forwardToPythonEngine(array $data, string $endpoint, ?string $apiKey = null): array
    {
        $api = new \Anibalealvarezs\AnalyticsApi\AnalyticsApi();
        // The host should ideally come from environment variables.
        $api->setHost($_ENV['ANALYTICS_ENGINE_HOST'] ?? 'http://analytics-engine:8050');
        
        // Setup Auth Key (Fallback to env if not provided dynamically by Facade)
        $apiKey = $apiKey ?? $_ENV['ANALYTICS_API_KEY'] ?? 'dev_secret_key';
        
        // Make the HTTP request
        // We use the basic post method from ApiClient skeleton
        try {
            $response = $api->post($endpoint, [
                'headers' => [
                    'X-Admin-API-Key' => $apiKey
                ],
                'json' => $data
            ]);
            
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\Exception $e) {
            // Forward the Python engine's HTTP error message if possible
            throw new \Exception("Analytics Engine Error: " . $e->getMessage());
        }
    }

    protected function errorResponse(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $code);
    }
}
