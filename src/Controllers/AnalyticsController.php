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
            } elseif (!empty($payload['calculate_trend_linear'])) {
                $sdkMethod = 'calculateTrendLinear';
            } elseif (!empty($payload['calculate_trend_sma'])) {
                $sdkMethod = 'calculateTrendSma';
            } elseif (!empty($payload['calculate_trend_ema'])) {
                $sdkMethod = 'calculateTrendEma';
            } elseif (!empty($payload['calculate_trend_holt_winters'])) {
                $sdkMethod = 'calculateTrendHoltWinters';
            } elseif (!empty($payload['calculate_trend_logarithmic'])) {
                $sdkMethod = 'calculateTrendLogarithmic';
            }

            // Forward to Python Analytics Engine if requested
            if ($sdkMethod) {
                $tPythonStart = microtime(true);
                $apiKey = $payload['admin_api_key'] ?? null;
                $engineHost = $payload['analytics_engine_host'] ?? null;
                
                // For bivariate stats (regression, elasticity, granger), we require an AST Operator bridge
                if ($requiresBivariate) {
                    if (!$node instanceof \Services\Analytics\VirtualMetricEngine\Nodes\OperatorNode) {
                        return $this->errorResponse("This statistic requires an Operator node at the root to split dependent and independent variables.", 500);
                    }
                    $ySeriesRaw = $node->getLeft()->evaluate($context);
                    $xSeriesRaw = $node->getRight()->evaluate($context);
                } else {
                    // For univariate stats (macd, anomaly, autocorrelation), we just evaluate the root node directly
                    $ySeriesRaw = $node->evaluate($context);
                    $xSeriesRaw = $ySeriesRaw; // Dummy clone to pass the alignment loop
                }
                
                if (is_array($ySeriesRaw) && is_array($xSeriesRaw)) {
                    // Normalize page URL keys across different sources (e.g. GA4 stores
                    // relative paths like "/es/" while GSC stores full URLs like
                    // "https://anibalalvarez.com/es/"). Extract just the path component
                    // from full URLs so keys match across channels, and strip query
                    // parameters that GA4 may include (e.g. "/es/?gad_source=1").
                    $normalizeKey = function (string $key): string {
                        if (preg_match('#^https?://[^/]+(/.*)$#', $key, $matches)) {
                            $key = $matches[1] ?: '/';
                        } elseif (preg_match('#^[a-zA-Z0-9-]+\.[a-zA-Z]{2,}(/.*)$#', $key, $matches)) {
                            $key = $matches[1] ?: '/';
                        }
                        $queryPos = strpos($key, '?');
                        if ($queryPos !== false) {
                            $key = substr($key, 0, $queryPos);
                        }
                        return $key;
                    };
                    $ySeriesRaw = array_combine(
                        array_map($normalizeKey, array_keys($ySeriesRaw)),
                        array_values($ySeriesRaw)
                    );
                    $xSeriesRaw = array_combine(
                        array_map($normalizeKey, array_keys($xSeriesRaw)),
                        array_values($xSeriesRaw)
                    );

                    $originalYSize = count($ySeriesRaw);
                    $originalXSize = count($xSeriesRaw);

                    $dates = array_intersect(array_keys($ySeriesRaw), array_keys($xSeriesRaw));
                    $zeroHandling = $payload['zero_handling'] ?? 'remove';

                    // Collect all aligned points (including zeros) in order
                    $alignedDates = [];
                    $alignedY = [];
                    $alignedX = [];
                    foreach ($dates as $date) {
                        $alignedDates[] = $date;
                        $alignedY[] = (float)$ySeriesRaw[$date];
                        $alignedX[] = (float)$xSeriesRaw[$date];
                    }

                    // Apply the chosen zero-handling strategy
                    switch ($zeroHandling) {
                        case 'keep':
                            $finalDates = array_map('strval', $alignedDates);
                            $yValues = $alignedY;
                            $xValues = $alignedX;
                            break;

                        case 'trim':
                            $firstNonZero = null;
                            $lastNonZero = null;
                            foreach ($alignedY as $i => $yVal) {
                                if (!empty($yVal) && !empty($alignedX[$i])) {
                                    if ($firstNonZero === null) $firstNonZero = $i;
                                    $lastNonZero = $i;
                                }
                            }
                            if ($firstNonZero === null) {
                                $finalDates = [];
                                $yValues = [];
                                $xValues = [];
                            } else {
                                $finalDates = array_map('strval', array_slice($alignedDates, $firstNonZero, $lastNonZero - $firstNonZero + 1));
                                $yValues = array_slice($alignedY, $firstNonZero, $lastNonZero - $firstNonZero + 1);
                                $xValues = array_slice($alignedX, $firstNonZero, $lastNonZero - $firstNonZero + 1);
                            }
                            break;

                        case 'remove':
                        default:
                            foreach ($alignedDates as $i => $date) {
                                if (!empty($alignedY[$i]) && !empty($alignedX[$i])) {
                                    $finalDates[] = (string)$date;
                                    $yValues[] = $alignedY[$i];
                                    $xValues[] = $alignedX[$i];
                                }
                            }
                            break;
                    }

                    // Apply dimension-based grouping for bivariate stats (e.g., group low-frequency queries/pages into "others")
                    if (!empty($payload['grouping']['enabled']) && $requiresBivariate) {
                        $grouped = $this->applyGroupByDimensionGrouping(
                            $finalDates, $yValues, $xValues, $payload['grouping']
                        );
                        $finalDates = $grouped['dates'];
                        $yValues = $grouped['y'];
                        $xValues = $grouped['x'];
                    }

                    $finalSize = count($finalDates);
                    $removedY = $originalYSize - $finalSize;
                    $removedX = $originalXSize - $finalSize;

                    if ($finalSize < 2) {
                        return new JsonResponse([
                            'success' => true,
                            'data' => [
                                'labels' => [],
                                'datasets' => [],
                                '_debug' => "Not enough overlapping non-zero data points for regression. Found: {$finalSize}. " .
                                    "Original Dependent (Y) size: {$originalYSize} (Removed: {$removedY}). " .
                                    "Original Independent (X) size: {$originalXSize} (Removed: {$removedX})."
                            ]
                        ]);
                    }
                        
                        $edgeCaseHandling = $payload['edge_case_handling'] ?? $payload['grouping'] ?? [
                            'weighted' => true,
                            'grouping' => 'none',
                        ];
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
                            ],
                            'edge_case_handling' => $edgeCaseHandling,
                        ];
                        if (!$requiresBivariate) {
                            // Strip dummy independent vars for univariate payloads to keep it clean
                            $regressionPayload['independent_vars'] = (object)[];
                        }
                        $pythonResponse = $this->forwardToPythonEngine($regressionPayload, $sdkMethod, $engineHost, $apiKey);
                        $result = $pythonResponse['data'] ?? $pythonResponse;
                        
                        if (isset($result['scatter_data']) && !empty($finalDates)) {
                            // Use Python's labels if available (correctly ordered after histogram grouping),
                            // otherwise fall back to original $finalDates order
                            if (empty($result['scatter_data']['labels'])) {
                                $result['scatter_data']['labels'] = array_values($finalDates);
                            }
                        }
                    } else {
                        return $this->errorResponse("The mathematical payload requires time-series array evaluation. Pass groupBy: ['daily'] in filters.", 500);
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

    /**
     * Group low-frequency dimension values (queries, pages, etc.) into a single "others" point
     * so outliers with very few events don't skew the regression line.
     *
     * Supported methods:
     *  - 'percentile' (default): groups the bottom N% of points sorted by the independent variable (x).
     *    The grouped points are replaced by their centroid (mean x, mean y) labeled as $label.
     *
     * @param array $dates  Dimension labels (query strings, page URLs, etc.)
     * @param array $y      Dependent variable values
     * @param array $x      Independent variable values (used as frequency proxy)
     * @param array $config Grouping configuration
     * @return array  ['dates' => string[], 'y' => float[], 'x' => float[]]
     */
    protected function applyGroupByDimensionGrouping(array $dates, array $y, array $x, array $config): array
    {
        $n = count($dates);
        if ($n < 3) {
            return ['dates' => $dates, 'y' => $y, 'x' => $x];
        }

        $method = $config['method'] ?? 'percentile';
        $value  = (float)($config['value'] ?? 25);
        $label  = $config['label'] ?? 'others';

        if ($method === 'percentile') {
            // Clamp percentile between 5 and 50
            $value = max(5, min(50, $value));
            $thresholdIndex = (int)ceil($n * $value / 100);
            $thresholdIndex = max(1, min($thresholdIndex, $n - 1));

            // Build combined tuple, sort by x ascending
            $combined = [];
            for ($i = 0; $i < $n; $i++) {
                $combined[] = ['date' => $dates[$i], 'y' => $y[$i], 'x' => $x[$i]];
            }
            usort($combined, fn($a, $b) => $a['x'] <=> $b['x']);

            $low  = array_slice($combined, 0, $thresholdIndex);
            $high = array_slice($combined, $thresholdIndex);

            // Single aggregated centroid for the low-frequency tail
            $meanX = array_sum(array_column($low, 'x')) / count($low);
            $meanY = array_sum(array_column($low, 'y')) / count($low);

            $result = array_merge(
                [['date' => $label, 'y' => $meanY, 'x' => $meanX]],
                $high
            );
            usort($result, fn($a, $b) => $a['x'] <=> $b['x']);

            return [
                'dates' => array_column($result, 'date'),
                'y'     => array_column($result, 'y'),
                'x'     => array_column($result, 'x'),
            ];
        }

        return ['dates' => $dates, 'y' => $y, 'x' => $x];
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
                           ->andWhere('c.name = :channelName')
                           ->setParameter('channelName', $channelName);
                           
                        $isArray = is_array($platformId);
                        if ($isArray) {
                            $qb->andWhere('ca.platformId IN (:platformId)')
                               ->setParameter('platformId', $platformId);
                        } else {
                            $qb->andWhere('ca.platformId = :platformId')
                               ->setParameter('platformId', $platformId)
                               ->setMaxResults(1);
                        }
                        
                        $result = $qb->getQuery()->getResult();

                        // Some channels (e.g. GSC) store platformId as MD5 hash of the URL
                        if (empty($result)) {
                            if ($isArray) {
                                $hashedIds = array_map(fn($id) => md5(trim($id)), $platformId);
                                $qb->setParameter('platformId', $hashedIds);
                            } else {
                                $qb->setParameter('platformId', md5(trim($platformId)));
                            }
                            $result = $qb->getQuery()->getResult();
                        }

                        if (!empty($result)) {
                            $ids = array_column($result, 'id');
                            // If array was passed, return array, else return string/int
                            $node['filters']['channeledAccount'] = $isArray ? $ids : $ids[0];
                            unset($node['filters']['asset_platform_id']);
                        } else {
                            $displayId = $isArray ? implode(', ', $platformId) : $platformId;
                            throw new \Exception("Asset with platform ID '{$displayId}' for channel '{$channelName}' has not been synced to APIs Hub yet.");
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
