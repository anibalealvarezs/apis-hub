<?php

namespace Services\Sync;

use Anibalealvarezs\TypeSafeApi\TypeSafeApi;
use Core\Database;
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
        $this->connection = Database::getConnection();

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

        // 1. Fetch queries associated with this asset that are missing either universal or asset-specific classification
        $sql = "
            SELECT DISTINCT q.id AS query_id, q.query
            FROM queries q
            JOIN metric_configs mc ON mc.query_id = q.id
            LEFT JOIN account_query_classifications aqc 
                ON aqc.query_id = q.id AND aqc.channeled_account_id = :asset_id
            WHERE mc.channeled_account_id = :asset_id
              AND aqc.query_id IS NULL
            LIMIT :limit
        ";

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('asset_id', $channeledAccountId);
        $stmt->bindValue('limit', $batchSize, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();
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

        // Prepare context strings
        $brand = $assetContext['brand'] ?? 'Official Brand';
        $businessDescription = $assetContext['description'] ?? 'E-commerce and online services';

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
                            'competitor' => 'Mentions rival companies, competing brands, or alternative commercial services'
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
}