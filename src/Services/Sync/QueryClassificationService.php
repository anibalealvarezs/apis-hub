<?php

namespace Services\Sync;

use Anibalealvarezs\TypeSafeApi\TypeSafeApi;
use DateTime;
use Doctrine\DBAL\Connection;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class QueryClassificationService
{
    protected ?TypeSafeApi $client = null;
    protected Connection $connection;
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Helpers::setLogger('query_classification.log');
        $this->connection = Helpers::getManager()->getConnection();

        $apiKey = getenv('TYPESAFE_API_KEY');
        if (!empty($apiKey)) {
            try {
                $baseUrl = getenv('TYPESAFE_BASE_URL') ?: 'https://api.typesafe.ai/v1/';
                $this->client = new TypeSafeApi(
                    apiKey: $apiKey,
                    baseUrl: $baseUrl,
                    logger: $this->logger
                );
            } catch (Exception $e) {
                $this->logger->warning("Could not instantiate TypeSafeApi: " . $e->getMessage());
            }
        }
    }

    /**
     * Check if TypeSafe classification is available.
     */
    public function isAvailable(): bool
    {
        return $this->client !== null;
    }

    /**
     * Classifies unclassified queries for a specific asset.
     *
     * @param int $channeledAccountId The asset ID (channeled_account_id)
     * @param array $assetContext Optional contextual details (e.g. brand names, description, industry)
     * @param int $batchSize Number of queries to process per chunk
     * @param int $minImpressions Filter to prioritize queries with traffic
     * @return array Summary of processed, classified, and skipped counts
     */
    public function classifyForAsset(
        int $channeledAccountId,
        array $assetContext = [],
        int $batchSize = 100,
        int $minImpressions = 1
    ): array {
        if (!$this->isAvailable()) {
            $this->logger->info("[QueryClassificationService] TypeSafe API key not configured. Skipping classification.");
            return [
                'status' => 'skipped',
                'reason' => 'No TypeSafe API key configured',
                'classified' => 0,
            ];
        }

        // 1. Fetch queries associated with this asset that are missing classification,
        // ordered by occurrence frequency in metric_configs (highest traffic/volume first)
        $sql = "
            SELECT q.id AS query_id, q.query, COUNT(mc.id) AS occurrences
            FROM queries q
            JOIN metric_configs mc ON mc.query_id = q.id
            LEFT JOIN account_query_classifications aqc 
                ON aqc.query_id = q.id AND aqc.channeled_account_id = :asset_id
            WHERE mc.channeled_account_id = :asset_id
              AND aqc.query_id IS NULL
            GROUP BY q.id, q.query
            ORDER BY occurrences DESC, q.id ASC
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'asset_id' => $channeledAccountId,
            'limit' => (int) $batchSize,
        ]);
        $candidates = $result->fetchAllAssociative();

        if (empty($candidates)) {
            return [
                'status' => 'completed',
                'candidates' => 0,
                'classified' => 0,
            ];
        }

        $totalClassified = 0;
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Resolve rich deterministic context (Explicit -> Live Site Metadata -> Page Slugs -> Domain Inference)
        $context = $this->resolveAssetContext($channeledAccountId, $assetContext);
        $brand = $context['brand'];
        $businessDescription = $context['description'];
        $competitorsList = $context['competitors'] ?? [];
        $competitorsStr = !empty($competitorsList) ? implode(', ', (array) $competitorsList) : '';

        $competitorCriteria = !empty($competitorsStr)
            ? "Mentions rival companies or competing brands ({$competitorsStr})"
            : 'Mentions rival companies, competing brands, or alternative commercial services';

        foreach ($candidates as $row) {
            $queryId = (int) $row['query_id'];
            $queryText = trim((string) $row['query']);

            if (empty($queryText)) {
                continue;
            }

            try {
                // Multi-question payload for TypeSafe System One
                $questions = [
                    'intent' => [
                        'type' => 'choice',
                        'instructions' => 'Determine search intent',
                        'choices' => ['informational', 'commercial', 'transactional', 'navigational'],
                        'criteria' => [
                            'informational' => 'Seeking instructions, tutorials, definitions, or general answers',
                            'commercial' => 'Comparing products, evaluating brands, reading reviews, or exploring options',
                            'transactional' => 'Ready to buy, seeking discounts, pricing, store locations, or checkout',
                            'navigational' => 'Looking for a specific website, official portal, login page, or brand domain'
                        ]
                    ],
                    'brand_relation' => [
                        'type' => 'choice',
                        'instructions' => "Determine relationship between the search term and the brand '{$brand}'",
                        'choices' => ['brand', 'non_brand', 'competitor'],
                        'criteria' => [
                            'brand' => "Contains the brand name '{$brand}', its official trademarks, or direct variations",
                            'non_brand' => 'Generic product or service term without any specific brand name',
                            'competitor' => $competitorCriteria
                        ]
                    ],
                    'business_relevance' => [
                        'type' => 'choice',
                        'instructions' => "Evaluate commercial relevance to the business described as: '{$businessDescription}'",
                        'choices' => ['core', 'adjacent', 'irrelevant'],
                        'criteria' => [
                            'core' => 'Direct commercial alignment with the products or services offered',
                            'adjacent' => 'Top-of-funnel discovery or indirectly related topics',
                            'irrelevant' => 'Accidental or parasitic traffic with zero business or conversion value'
                        ]
                    ]
                ];

                $response = $this->client->evaluate(
                    state: [
                        'search_query' => $queryText,
                        'business_name' => $brand,
                        'business_context' => $businessDescription
                    ],
                    questions: $questions
                );

                $answers = $response['answers'] ?? [];

                // 2. Persist Universal Classification (Intent + Language)
                $intent = $answers['intent']['choice'] ?? null;
                $intentConf = $answers['intent']['confidence'] ?? null;

                if ($intent) {
                    $this->connection->executeStatement("
                        INSERT INTO query_classifications (query_id, intent, confidence, classified_at)
                        VALUES (:query_id, :intent, :confidence, :classified_at)
                        ON CONFLICT (query_id) DO UPDATE 
                        SET intent = EXCLUDED.intent,
                            confidence = EXCLUDED.confidence,
                            classified_at = EXCLUDED.classified_at
                    ", [
                        'query_id' => $queryId,
                        'intent' => $intent,
                        'confidence' => $intentConf,
                        'classified_at' => $now,
                    ]);
                }

                // 3. Persist Asset Scope Classification (Brand Relation + Business Relevance)
                $brandRelation = $answers['brand_relation']['choice'] ?? 'non_brand';
                $relevance = $answers['business_relevance']['choice'] ?? 'adjacent';
                $assetConf = min($answers['brand_relation']['confidence'] ?? 1.0, $answers['business_relevance']['confidence'] ?? 1.0);

                $this->connection->executeStatement("
                    INSERT INTO account_query_classifications 
                        (channeled_account_id, query_id, brand_relation, business_relevance, confidence, classified_at)
                    VALUES 
                        (:asset_id, :query_id, :brand_relation, :business_relevance, :confidence, :classified_at)
                    ON CONFLICT (channeled_account_id, query_id) DO UPDATE
                    SET brand_relation = EXCLUDED.brand_relation,
                        business_relevance = EXCLUDED.business_relevance,
                        confidence = EXCLUDED.confidence,
                        classified_at = EXCLUDED.classified_at
                ", [
                    'asset_id' => $channeledAccountId,
                    'query_id' => $queryId,
                    'brand_relation' => $brandRelation,
                    'business_relevance' => $relevance,
                    'confidence' => $assetConf,
                    'classified_at' => $now,
                ]);

                $totalClassified++;
            } catch (Exception $e) {
                $this->logger->error("[QueryClassificationService] Error evaluating query #{$queryId} ('{$queryText}'): " . $e->getMessage());
            }
        }

        return [
            'status' => 'completed',
            'candidates' => count($candidates),
            'classified' => $totalClassified,
        ];
    }

    /**
     * Resolves the deterministic semantic context (Brand & Business Description) for an asset.
     * Hierarchy:
     *   1. Explicitly provided context ($providedContext / channeled_accounts.data->'ai_context')
     *   2. Native website homepage metadata (<title> & <meta name="description">)
     *   3. Deterministic frequency extraction from synced page paths/slugs
     *   4. Lexical domain segmentation fallback
     */
    public function resolveAssetContext(int $channeledAccountId, array $providedContext = []): array
    {
        // 1. Check if fully provided
        $brand = !empty($providedContext['brand']) ? trim((string) $providedContext['brand']) : null;
        $description = !empty($providedContext['description']) ? trim((string) $providedContext['description']) : null;
        $competitors = !empty($providedContext['competitors']) ? $providedContext['competitors'] : null;

        // Fetch channeled_account record
        $accountData = $this->connection->fetchAssociative(
            "SELECT name, platform_id, data FROM channeled_accounts WHERE id = :id",
            ['id' => $channeledAccountId]
        );

        // Check if custom ai_context is stored in channeled_accounts.data
        if ($accountData && !empty($accountData['data'])) {
            $meta = is_string($accountData['data']) ? json_decode($accountData['data'], true) : $accountData['data'];
            if (is_array($meta) && !empty($meta['ai_context'])) {
                $brand = $brand ?: ($meta['ai_context']['brand'] ?? null);
                $description = $description ?: ($meta['ai_context']['description'] ?? null);
                $competitors = $competitors ?: ($meta['ai_context']['competitors'] ?? null);
            }
        }

        $competitorsList = [];
        if (!empty($competitors)) {
            $competitorsList = is_array($competitors) ? $competitors : array_map('trim', explode(',', (string) $competitors));
            $competitorsList = array_values(array_filter($competitorsList));
        }

        $rawName = (string) ($accountData['name'] ?? '');
        $cleanDomain = preg_replace('/^(sc-domain:|https?:\/\/|www\.)/i', '', $rawName);
        $cleanDomain = rtrim($cleanDomain, '/');
        $brandBase = preg_replace('/\.(com|org|net|es|ec|io|co|me|cloud|ai|dev)(\.[a-z]{2})?$/i', '', $cleanDomain);
        $brandFormatted = ucwords(str_replace(['-', '.', '_'], ' ', $brandBase));

        if (!$brand) {
            $brand = !empty($brandFormatted) ? "{$brandFormatted} ({$cleanDomain})" : 'Official Brand';
        }

        if ($description) {
            return [
                'brand' => $brand,
                'description' => $description,
                'competitors' => $competitorsList,
            ];
        }

        // 2. Native Website Homepage Metadata (Zero-Touch HTTP inspection with 2s timeout)
        $metaDescription = $this->fetchHomepageMetadata($cleanDomain);
        if ($metaDescription) {
            return [
                'brand' => $brand,
                'description' => "{$brand}. " . $metaDescription,
                'competitors' => $competitorsList,
            ];
        }

        // 3. Deterministic Frequency Extraction from Synced Pages
        $pageTopics = $this->extractTopicsFromPages($channeledAccountId, $cleanDomain);
        if (!empty($pageTopics)) {
            $topicsStr = implode(', ', $pageTopics);
            return [
                'brand' => $brand,
                'description' => "Official website and services for {$cleanDomain} ({$brandFormatted}). Core topics: {$topicsStr}",
                'competitors' => $competitorsList,
            ];
        }

        // 4. Default Domain-based fallback
        return [
            'brand' => $brand,
            'description' => "Official brand, website and digital services for {$cleanDomain} ({$brandFormatted})",
            'competitors' => $competitorsList,
        ];
    }

    /**
     * Performs a lightweight HTTP GET to the homepage to extract title and meta description.
     */
    protected function fetchHomepageMetadata(string $domain): ?string
    {
        if (empty($domain) || !str_contains($domain, '.')) {
            return null;
        }

        try {
            $url = "https://{$domain}";
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2,
                    'follow_location' => 1,
                    'max_redirects' => 2,
                    'user_agent' => 'APIs-Hub-Crawler/1.0',
                    'header' => "Accept: text/html\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);

            // Read only the first 16KB of the HTML response to avoid downloading assets/body
            $stream = @fopen($url, 'r', false, $ctx);
            if (!$stream) {
                return null;
            }

            $html = @stream_get_contents($stream, 16384);
            @fclose($stream);

            if (empty($html)) {
                return null;
            }

            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1])));
            }

            $metaDesc = '';
            if (preg_match('/<meta[^>]+name=[\'"]description[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/is', $html, $m)) {
                $metaDesc = trim(html_entity_decode(strip_tags($m[1])));
            }

            $combined = trim("{$title} - {$metaDesc}", " -");
            return !empty($combined) ? substr($combined, 0, 250) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extracts top distinct keyword stems from synced page slugs for an asset.
     */
    protected function extractTopicsFromPages(int $channeledAccountId, string $domain): array
    {
        try {
            $sql = "
                SELECT DISTINCT p.url
                FROM pages p
                JOIN metric_configs mc ON mc.page_id = p.id
                WHERE mc.channeled_account_id = :asset_id
                  AND p.url IS NOT NULL
                LIMIT 40
            ";

            $urls = $this->connection->fetchFirstColumn($sql, ['asset_id' => $channeledAccountId]);
            if (empty($urls)) {
                return [];
            }

            $stopwords = [
                'http', 'https', 'www', 'com', 'org', 'net', 'es', 'ec', 'html', 'php',
                'page', 'tag', 'category', 'categoria', 'servicios', 'services', 'author',
                'inicio', 'home', 'blog', 'contacto', 'contact', 'terminos', 'privacidad',
                'politica', 'index', 'item', 'product', 'post', 'articulo', 'sobre', 'about'
            ];

            $wordCounts = [];
            foreach ($urls as $u) {
                $path = parse_url($u, PHP_URL_PATH);
                if (!$path || $path === '/' || $path === '') {
                    continue;
                }

                // Split path by slashes, dashes, dots, underscores
                $tokens = preg_split('/[\/\-_\.\?&=]+/', strtolower($path));
                foreach ($tokens as $t) {
                    $t = trim($t);
                    if (strlen($t) >= 4 && !in_array($t, $stopwords) && !is_numeric($t)) {
                        $wordCounts[$t] = ($wordCounts[$t] ?? 0) + 1;
                    }
                }
            }

            if (empty($wordCounts)) {
                return [];
            }

            arsort($wordCounts);
            return array_slice(array_keys($wordCounts), 0, 8);
        } catch (\Throwable $e) {
            return [];
        }
    }
}