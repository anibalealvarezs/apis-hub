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

            $parser = new AstParser();
            $node = $parser->parse($payload['ast']);
            
            // Use AstDataHydrator to automatically extract required metrics and fetch them
            $hydrator = new \Services\Analytics\VirtualMetricEngine\AstDataHydrator($this->em);
            $filters = $payload['filters'] ?? [];
            $metricData = $hydrator->hydrate($node, $filters);
            
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
        $host = $_ENV['ANALYTICS_ENGINE_HOST'] ?? 'http://analytics-engine:8050';
        $apiKey = $apiKey ?? $_ENV['ANALYTICS_API_KEY'] ?? 'dev_secret_key';
        
        // Instantiate the analytics-api client using this dynamic key and host
        $api = new \Anibalealvarezs\AnalyticsApi\AnalyticsApi($host, $apiKey);
        
        try {
            // The SDK now inherently injects the X-Admin-API-Key based on the constructor
            $response = $api->post($endpoint, [
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
