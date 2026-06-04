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

            // Translate Facade external platform IDs to internal APIs Hub IDs
            $this->translatePlatformIds($payload['ast'], $this->em);

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
            $sdkMethod = null;
            $requiresBivariate = false;

            if (!empty($payload['calculate_regression'])) {
                $sdkMethod = 'calculateRegression';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_elasticity'])) {
                $sdkMethod = 'calculateElasticity';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_granger'])) {
                $sdkMethod = 'calculateGranger';
                $requiresBivariate = true;
            } elseif (!empty($payload['calculate_autocorrelation'])) {
                $sdkMethod = 'calculateAutocorrelation';
            } elseif (!empty($payload['calculate_macd'])) {
                $sdkMethod = 'calculateMacd';
            } elseif (!empty($payload['calculate_anomaly'])) {
                $sdkMethod = 'calculateAnomaly';
            }

            // Forward to Python Analytics Engine if requested
            if ($sdkMethod) {
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
                        $pythonResponse = $this->forwardToPythonEngine($regressionPayload, $sdkMethod, $engineHost, $apiKey);
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

    protected function forwardToPythonEngine(array $data, string $sdkMethod, ?string $host = null, ?string $apiKey = null): array
    {
        // Enforce agnostic architecture: strictly rely on payload injection or the default cloud SaaS
        $host = $host ?? 'https://analytics.apis-hub.cloud/';
        $apiKey = $apiKey ?? $_ENV['ANALYTICS_API_KEY'] ?? 'dev_secret_key';
        
        // Instantiate the analytics-api client using this dynamic key and host
        $api = new \Anibalealvarezs\AnalyticsApi\AnalyticsApi($host, $apiKey);
        
        if (!method_exists($api, $sdkMethod)) {
            throw new \Exception("Analytics Engine SDK Error: Method {$sdkMethod} does not exist.");
        }
        
        try {
            // The SDK inherently injects the API key and routes to the correct endpoint
            $response = call_user_func([$api, $sdkMethod], $data);
            
            return $response;
        } catch (\Exception $e) {
            // Forward the Python engine's HTTP error message if possible
            throw new \Exception("Analytics Engine Error: " . $e->getMessage());
        }
    }

    protected function errorResponse(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $code);
    }

    /**
     * Recursively traverses the AST payload and maps any external 'asset_platform_id'
     * to the internal 'channeledAccount' ID of the corresponding APIs Hub entity.
     *
     * @param array $node
     * @param \Doctrine\ORM\EntityManager $em
     * @throws \Exception
     */
    protected function translatePlatformIds(array &$node, \Doctrine\ORM\EntityManager $em): void
    {
        if (isset($node['type'])) {
            if ($node['type'] === 'metric') {
                if (isset($node['filters']['asset_platform_id'])) {
                    $platformId = $node['filters']['asset_platform_id'];
                    $metricString = $node['metric'] ?? '';
                    $parts = explode('.', $metricString, 2);
                    $channelName = count($parts) === 2 ? $parts[0] : 'global';

                    if ($channelName !== 'global') {
                        $qb = $em->createQueryBuilder();
                        $qb->select('ca.id')
                           ->from(\Entities\Analytics\Channeled\ChanneledAccount::class, 'ca')
                           ->join('ca.channel', 'c')
                           ->where('ca.platformId = :platformId')
                           ->andWhere('c.name = :channelName')
                           ->setParameter('platformId', $platformId)
                           ->setParameter('channelName', $channelName)
                           ->setMaxResults(1);
                        
                        $result = $qb->getQuery()->getOneOrNullResult();
                        if ($result) {
                            $node['filters']['channeledAccount'] = $result['id'];
                            unset($node['filters']['asset_platform_id']);
                        } else {
                            throw new \Exception("Asset with platform ID '{$platformId}' for channel '{$channelName}' has not been synced to APIs Hub yet.");
                        }
                    }
                }
            } elseif ($node['type'] === 'operator') {
                if (isset($node['left']) && is_array($node['left'])) {
                    $this->translatePlatformIds($node['left'], $em);
                }
                if (isset($node['right']) && is_array($node['right'])) {
                    $this->translatePlatformIds($node['right'], $em);
                }
            }
        }
    }
}
