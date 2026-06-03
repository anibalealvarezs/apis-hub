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
        $logger = \Helpers\Helpers::setLogger('analytics.log');
        $logger->info("--- Incoming AST compute request ---");
        $startTime = microtime(true);
        
        try {
            $logger->info("1. Getting request content...");
            $content = $request->getContent();
            $logger->info("2. JSON decoding payload...");
            $payload = json_decode($content, true);

            if (!isset($payload['ast'])) {
                $logger->error("Missing AST payload.");
                return $this->errorResponse('Missing AST payload.', 400);
            }

            $logger->info("3. Initializing AstParser...");
            $tParseStart = microtime(true);
            $parser = new AstParser();
            
            $logger->info("4. Parsing AST...");
            $node = $parser->parse($payload['ast']);
            $logger->info("AST parsed in " . round((microtime(true) - $tParseStart) * 1000, 2) . "ms");
            
            $logger->info("5. Initializing AstDataHydrator...");
            $tHydrateStart = microtime(true);
            $hydrator = new \Services\Analytics\VirtualMetricEngine\AstDataHydrator($this->em, $logger);
            $filters = $payload['filters'] ?? [];
            
            $logger->info("6. Starting AST Hydration...");
            $metricData = $hydrator->hydrate($node, $filters);
            $logger->info("Total Hydration completed in " . round((microtime(true) - $tHydrateStart) * 1000, 2) . "ms", ['metrics' => $metricData]);
            
            $logger->info("7. Initializing EvaluationContext...");
            $tEvalStart = microtime(true);
            $context = new EvaluationContext($metricData);
            
            $logger->info("8. Evaluating Node...");
            $result = $node->evaluate($context);
            $logger->info("Mathematical evaluation completed in " . round((microtime(true) - $tEvalStart) * 1000, 2) . "ms");

            // Determine which Python engine statistic is requested
            $pythonEndpoint = null;
            $requiresBivariate = false;

            if (!empty($payload['calculate_regression'])) {
                $pythonEndpoint = 'api/v1/stats/regression';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_elasticity'])) {
                $pythonEndpoint = 'api/v1/stats/elasticity';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_granger'])) {
                $pythonEndpoint = 'api/v1/stats/granger';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_autocorrelation'])) {
                $pythonEndpoint = 'api/v1/stats/autocorrelation';
            } elseif (!empty($payload['calculate_macd'])) {
                $pythonEndpoint = 'api/v1/stats/macd';
            } elseif (!empty($payload['calculate_anomaly'])) {
                $pythonEndpoint = 'api/v1/stats/anomaly';
            }

            // Forward to Python Analytics Engine if requested
            if ($pythonEndpoint) {
                $tPythonStart = microtime(true);
                $apiKey = $payload['admin_api_key'] ?? null;
                $engineHost = $payload['analytics_engine_host'] ?? null;
                
                // For bivariate stats (regression, elasticity, granger), we require an AST Operator bridge
                if ($requiresBivariate) {
                    if (!$node instanceof \Services\Analytics\VirtualMetricEngine\Nodes\OperatorNode) {
                        throw new Exception("This statistic requires an Operator node at the root to split dependent and independent variables.");
                    }
                    $ySeriesRaw = $node->getLeft()->evaluate($context);
                    $xSeriesRaw = $node->getRight()->evaluate($context);
                } else {
                    // For univariate stats (macd, anomaly, autocorrelation), we just evaluate the root node directly
                    $ySeriesRaw = $node->evaluate($context);
                    $xSeriesRaw = $ySeriesRaw; // Dummy clone to pass the alignment loop
                }
                
                if (is_array($ySeriesRaw) && is_array($xSeriesRaw)) {
                    $originalYSize = count($ySeriesRaw);
                    $originalXSize = count($xSeriesRaw);

                    $dates = array_intersect(array_keys($ySeriesRaw), array_keys($xSeriesRaw));
                    $yValues = [];
                    $xValues = [];
                    $finalDates = [];
                    
                    foreach ($dates as $date) {
                        $yVal = (float)$ySeriesRaw[$date];
                        $xVal = (float)$xSeriesRaw[$date];
                            
                            // Automatically treat the data: only include overlapping dates where both metrics have actual non-zero data
                            if (!empty($yVal) && !empty($xVal)) {
                                $finalDates[] = $date;
                                $yValues[] = $yVal;
                                $xValues[] = $xVal;
                            }
                        }
                        
                        $finalSize = count($finalDates);
                        $removedY = $originalYSize - $finalSize;
                        $removedX = $originalXSize - $finalSize;

                        // Regression mathematically requires at least 2 points (preferably more)
                        if ($finalSize < 2) {
                            throw new Exception(
                                "Not enough overlapping non-zero data points for regression. Found: {$finalSize}. " .
                                "Original Dependent (Y) size: {$originalYSize} (Removed: {$removedY}). " .
                                "Original Independent (X) size: {$originalXSize} (Removed: {$removedX})."
                            );
                        }
                        
                        $regressionPayload = [
                            'dependent_var' => [
                                'dates' => $finalDates,
                                'values' => $yValues
                            ],
                            'independent_vars' => [
                                'x1' => [
                                    'dates' => $finalDates,
                                    'values' => $xValues
                                ]
                            ]
                        ];
                        if (!$requiresBivariate) {
                            // Strip dummy independent vars for univariate payloads to keep it clean
                            $regressionPayload['independent_vars'] = (object)[];
                        }
                        $pythonResponse = $this->forwardToPythonEngine($regressionPayload, $pythonEndpoint, $engineHost, $apiKey);
                        $result = $pythonResponse['data'] ?? $pythonResponse;
                    } else {
                        throw new Exception("The mathematical payload requires time-series array evaluation. Pass groupBy: ['daily'] in filters.");
                    }

                $logger->info("Python engine request completed in " . round((microtime(true) - $tPythonStart) * 1000, 2) . "ms");
            }

            $logger->info("Request completed successfully in " . round((microtime(true) - $startTime) * 1000, 2) . "ms");

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Throwable $e) {
            if (isset($logger)) {
                $logger->error("Computation error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    protected function forwardToPythonEngine(array $data, string $endpoint, ?string $host = null, ?string $apiKey = null): array
    {
        // Enforce agnostic architecture: strictly rely on payload injection or the default cloud SaaS
        $host = $host ?? 'https://analytics.apis-hub.cloud/';
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
