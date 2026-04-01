<?php

namespace Repositories;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Entity;
use Enums\QueryBuilderType;
use Exception;
use Helpers\Helpers;
use ReflectionException;

class BaseRepository extends EntityRepository
{
    /**
     * List of top-level result fields to strip before returning the response.
     * Set via setHideFields() from the controller layer.
     */
    private array $hideFields = [];
    protected bool $isChanneledMetric = false;
    private array $activeAggregateJoins = [];
    private bool $needsImpressionsJoin = false;
    protected static array $relationMap = [
        'query'    => ['table' => 'queries', 'fk' => 'query_id', 'field' => 'query', 'alias' => 'rq'],
        'page'     => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'url', 'alias' => 'rp'],
        'account'  => ['table' => 'accounts', 'fk' => 'account_id', 'field' => 'name', 'alias' => 'ra'],
        'campaign' => ['table' => 'campaigns', 'fk' => 'campaign_id', 'field' => 'name', 'alias' => 'rc'],
        'channeledAccount'  => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'name', 'alias' => 'rca'],
        'channeled_account_id' => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'id', 'alias' => 'rca'],
        'channeledCampaign' => ['table' => 'channeled_campaigns', 'fk' => 'channeled_campaign_id', 'field' => 'platform_id', 'alias' => 'rcc'],
        'adGroup'  => ['table' => 'channeled_ad_groups', 'fk' => 'channeled_ad_group_id', 'field' => 'name', 'alias' => 'rag'],
        'ad'       => ['table' => 'channeled_ads', 'fk' => 'channeled_ad_id', 'field' => 'name', 'alias' => 'rad'],
        'creative' => ['table' => 'creatives', 'fk' => 'creative_id', 'field' => 'name', 'alias' => 'rcre'],
        'country'  => ['table' => 'countries', 'fk' => 'country_id', 'field' => 'name', 'alias' => 'rcty'],
        'device'   => ['table' => 'devices', 'fk' => 'device_id', 'field' => 'type', 'alias' => 'rd'],
        'page_title' => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'title', 'alias' => 'rp_t'],
        'page_platform_id' => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'platform_id', 'alias' => 'rp_p'],
        'linked_fb_page_id' => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'data', 'alias' => 'rca', 'isJSON' => true, 'jsonPath' => 'facebook_page_id'],
        'post'      => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'post_id', 'alias' => 'rpo'],
        'post_id'   => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'post_id', 'alias' => 'rpo_id'],
        'permalink_url' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_pu', 'isJSON' => true, 'jsonPath' => 'permalink_url'],
        'permalink' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_pl', 'isJSON' => true, 'jsonPath' => 'permalink'],
        'timestamp' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_ts', 'isJSON' => true, 'jsonPath' => 'timestamp'],
        'created_time' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_ct', 'isJSON' => true, 'jsonPath' => 'created_time'],
        'media_type' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_mt', 'isJSON' => true, 'jsonPath' => 'media_type'],
        'message' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_msg', 'isJSON' => true, 'jsonPath' => 'message'],
        'caption' => ['table' => 'posts', 'fk' => 'post_id', 'field' => 'data', 'alias' => 'rpo_cap', 'isJSON' => true, 'jsonPath' => 'caption'],
    ];

    /**
     * Get the minimum date available for these metrics.
     */
    public function getMinDate(array|\stdClass $filters = []): ?string
    {
        $dateField = $this->getDateFieldName();
        $qb = $this->createQueryBuilder('e')
            ->select("MIN(e.$dateField)");
        
        foreach ($filters as $key => $value) {
            if ($this->_class->hasField($key)) {
                $qb->andWhere("e.$key = :$key")
                   ->setParameter($key, $value);
            }
        }

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get the maximum date available for these metrics.
     */
    public function getMaxDate(array|\stdClass $filters = []): ?string
    {
        $dateField = $this->getDateFieldName();
        $qb = $this->createQueryBuilder('e')
            ->select("MAX(e.$dateField)");
        
        foreach ($filters as $key => $value) {
            if ($this->_class->hasField($key)) {
                $qb->andWhere("e.$key = :$key")
                   ->setParameter($key, $value);
            }
        }

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get the date field name for the current entity.
     */
    protected function getDateFieldName(): string
    {
        $entityClass = $this->getEntityName();
        if (str_contains($entityClass, 'Channeled')) {
            return 'platformCreatedAt';
        }
        return 'metricDate';
    }

    /**
     * Set the list of fields to hide from the result.
     *
     * @param string[] $fields
     * @return static
     */
    public function setHideFields(array $fields): static
    {
        $this->hideFields = $fields;
        return $this;
    }

    /**
     * Remove any fields listed in $this->hideFields from the top level of a result array.
     *
     * @param array $result
     * @return array
     */
    protected function applyHideFields(array $result): array
    {
        foreach ($this->hideFields as $field) {
            unset($result[trim($field)]);
        }
        return $result;
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilder(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        return $this->createBaseQueryBuilderNoJoins($type);
    }

    /**
     * @param QueryBuilderType $type
     * @return QueryBuilder
     * @throws Exception
     */
    protected function createBaseQueryBuilderNoJoins(QueryBuilderType $type = QueryBuilderType::SELECT): QueryBuilder
    {
        $query = $this->_em->createQueryBuilder();
        match ($type) {
            QueryBuilderType::LAST, QueryBuilderType::SELECT => $query->select('e'),
            QueryBuilderType::COUNT => $query->select('count(e.id)'),
            QueryBuilderType::AGGREGATE => $query, // Select will be set by buildAggregateQuery
            QueryBuilderType::CUSTOM => throw new Exception('To be implemented'),
        };

        return $query->from($this->getEntityName(), 'e');
    }

    /**
     * @param array $aggregations map of "alias" => "FUNCTION(field)"
     * @param array $groupBy list of fields to group by
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     * @throws Exception
     */
    public function aggregate(
        array $aggregations,
        array $groupBy = [],
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $orderBy = null,
        ?string $orderDir = 'ASC'
    ): array {
        $connection = $this->_em->getConnection();
        $qb = $connection->createQueryBuilder();
        $tableName = $this->_class->getTableName();
        $qb->from($tableName, 'e');

        // Specialized logic for Metric entities (Metric or ChanneledMetric) to support deep joins
        $this->activeAggregateJoins = [];
        $entityName = $this->getEntityName();
        $this->isChanneledMetric = str_ends_with($entityName, 'ChanneledMetric');
        $isMetric = str_ends_with($entityName, 'Analytics\Metric');

        if ($this->isChanneledMetric) {
            $qb->join('e', 'metrics', 'm', 'e.metric_id = m.id')
               ->join('m', 'metric_configs', 'mc', 'm.metric_config_id = mc.id');
            
            $this->activeAggregateJoins['m'] = true;
            $this->activeAggregateJoins['mc'] = true;
        } elseif ($isMetric) {
            $qb->join('e', 'metric_configs', 'mc', 'e.metric_config_id = mc.id');
            $this->activeAggregateJoins['mc'] = true;
        }

        // Selects with aggregation functions
        $this->needsImpressionsJoin = false;
        foreach ($aggregations as $expr) {
            if (str_contains(strtolower($expr), 'position')) {
                $this->needsImpressionsJoin = true;
                break;
            }
        }

        if ($this->needsImpressionsJoin && $this->isChanneledMetric) {
            // Reverted JOIN due to performance impact on non-indexed tables
        }

        foreach ($aggregations as $alias => $expr) {
            $parsedExpr = $this->mapFieldToSql($expr, true);
            
            // Safety check for ChanneledMetric to prevent raw 'value' aggregation
            // We only allow m.value if it's wrapped in a CASE WHEN (our formulas)
            if ($this->isChanneledMetric && preg_match('/\bm\.value\b/i', $parsedExpr) && !str_contains($parsedExpr, 'CASE WHEN')) {
                throw new \InvalidArgumentException(
                    "Direct aggregation of 'value' field is restricted for ChanneledMetrics to prevent data corruption. " .
                    "Please use intelligent formulas (e.g., 'spend', 'clicks', 'ctr', 'cpc', 'cpm', 'frequency', 'position') " .
                    "or filter specifically by 'name' before aggregating."
                );
            }

            $qb->addSelect("$parsedExpr AS $alias");
        }

        $standardRelations = array_keys(self::$relationMap);
        $dateFields = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'year', 'month', 'day', 'week', 'quarter', 'dayofweek', 'dayname', 'monthname', 'metricDate', 'platformCreatedAt', 'createdAt', 'date'];

        $safeLeftJoin = function(string $from, string $table, string $alias, string $condition) use ($qb) {
            $currentJoins = $qb->getQueryPart('join');
            foreach ($currentJoins as $joins) {
                foreach ($joins as $join) {
                    if ($join['joinAlias'] === $alias) return;
                }
            }
            $qb->leftJoin($from, $table, $alias, $condition);
        };

        // Grouping and dimension handling
        foreach ($groupBy as $field) {
            $isPostgres = Helpers::isPostgres();
            $quoteChar = $isPostgres ? '"' : '`';
            
            // Virtual aliases like linked_fb_page_id shouldn't be quoted for result mapping consistency
            $quotedField = $field; 
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                $quotedField = $quoteChar . $field . $quoteChar;
            }
            $isDimension = str_starts_with($field, 'dimensions.');
            $dimKey = $isDimension ? substr($field, 11) : $field;

            // Automatic dimension detection: if it's a ChanneledMetric taxonomy and not a standard relation/date/field
            if (($isMetric || $this->isChanneledMetric) && ($isDimension || ($field !== 'account_type' && !in_array($field, $standardRelations) && !in_array($field, $dateFields) && !$this->_class->hasField($field)))) {
                $dimAlias = "dim_" . preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                $qb->setParameter("key_$dimAlias", $dimKey);
                
                // Optimized join strategy to prevent Cartesian product without using junction table ID
                // Each branch ONLY captures its intended dimension value from the set
                $dsiCondition = "e.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE sub_dk.name = :key_$dimAlias
                )";
                
                $safeLeftJoin('e', 'dimension_set_items', "dsi_$dimAlias", $dsiCondition);
                $safeLeftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");
                
                $qb->addSelect("dv_$dimAlias.value AS $quotedField")
                   ->addGroupBy("dv_$dimAlias.value");
            } elseif (($isMetric || $this->isChanneledMetric) && in_array($field, ['account', 'campaign'])) {
                $isAccount = $field === 'account';
                $genericKey = $isAccount ? 'account' : 'campaign';
                $channeledKey = $isAccount ? 'channeledAccount' : 'channeledCampaign';
                $genericMap = self::$relationMap[$genericKey];
                $channeledMap = self::$relationMap[$channeledKey];
                
                $safeLeftJoin('mc', $genericMap['table'], $genericMap['alias'], "mc.{$genericMap['fk']} = {$genericMap['alias']}.id");
                $safeLeftJoin('mc', $channeledMap['table'], $channeledMap['alias'], "mc.{$channeledMap['fk']} = {$channeledMap['alias']}.id");

                if ($isAccount) {
                    $campaignMap = self::$relationMap['channeledCampaign'];
                    $safeLeftJoin('mc', $campaignMap['table'], $campaignMap['alias'], "mc.{$campaignMap['fk']} = {$campaignMap['alias']}.id");
                    $safeLeftJoin($campaignMap['alias'], 'channeled_accounts', 'rca_fallback', "{$campaignMap['alias']}.channeled_account_id = rca_fallback.id");
                    
                    $castType = Helpers::isPostgres() ? 'VARCHAR' : 'CHAR';
                    $quotedFieldId = $quoteChar . $field . "_id" . $quoteChar;
                    $qb->addSelect("COALESCE(CAST({$channeledMap['alias']}.{$channeledMap['field']} AS $castType), CAST(rca_fallback.name AS $castType), CAST({$genericMap['alias']}.{$genericMap['field']} AS $castType), CAST({$channeledMap['alias']}.platform_id AS $castType), CAST(mc.{$channeledMap['fk']} AS $castType), CAST(mc.{$genericMap['fk']} AS $castType), 'Unknown') AS $quotedField")
                       ->addSelect("mc.{$channeledMap['fk']} AS $quotedFieldId")
                       ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                       ->addGroupBy("rca_fallback.name")
                       ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                       ->addGroupBy("{$channeledMap['alias']}.platform_id")
                       ->addGroupBy("mc.{$channeledMap['fk']}")
                       ->addGroupBy("mc.{$genericMap['fk']}");
                } else {
                    if (isset($genericMap['isJSON']) && $genericMap['isJSON']) {
                        $sqlField = $this->mapFieldToSql($field);
                        $qb->addSelect("COALESCE($sqlField, 'N/A') AS $quotedField")
                           ->addGroupBy($sqlField);
                    } else {
                        $quotedFieldId = $quoteChar . $field . "_id" . $quoteChar;
                        $castType = Helpers::isPostgres() ? 'VARCHAR' : 'CHAR';
                        $qb->addSelect("COALESCE(CAST({$genericMap['alias']}.{$genericMap['field']} AS $castType), CAST({$channeledMap['alias']}.{$channeledMap['field']} AS $castType), CAST({$channeledMap['alias']}.platform_id AS $castType), CAST(mc.{$channeledMap['fk']} AS $castType), CAST(mc.{$genericMap['fk']} AS $castType), 'Unknown') AS $quotedField")
                           ->addSelect("mc.{$channeledMap['fk']} AS $quotedFieldId")
                           ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                           ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                           ->addGroupBy("{$channeledMap['alias']}.platform_id")
                           ->addGroupBy("mc.{$channeledMap['fk']}")
                           ->addGroupBy("mc.{$genericMap['fk']}");
                    }
                }
            } elseif (($isMetric || $this->isChanneledMetric) && isset(self::$relationMap[$field])) {
                $map = self::$relationMap[$field];
                $safeLeftJoin('mc', $map['table'], $map['alias'], "mc.{$map['fk']} = {$map['alias']}.id");
                
                $castType = Helpers::isPostgres() ? 'VARCHAR' : 'CHAR';
                if (isset($map['isJSON']) && $map['isJSON']) {
                    $sqlField = $this->mapFieldToSql($field);
                    $qb->addSelect("COALESCE($sqlField, 'N/A') AS $quotedField")
                       ->addGroupBy($sqlField);
                } else {
                    $quotedFieldId = $quoteChar . $field . "_id" . $quoteChar;
                    $qb->addSelect("COALESCE(CAST({$map['alias']}.{$map['field']} AS $castType), CAST(mc.{$map['fk']} AS $castType), 'Unknown') AS $quotedField")
                       ->addSelect("mc.{$map['fk']} AS $quotedFieldId")
                       ->addGroupBy("{$map['alias']}.{$map['field']}")
                       ->addGroupBy("mc.{$map['fk']}");
                }
            } else {
                $sqlField = $this->mapFieldToSql($field);
                $qb->addSelect("$sqlField AS $quotedField")
                   ->addGroupBy($sqlField);
            }
        }

        // Apply filters
        if ($filters) {
            foreach ($filters as $key => $value) {
                $isDimension = str_starts_with($key, 'dimensions.');
                $dimKey = $isDimension ? substr($key, 11) : $key;

                if ($this->isChanneledMetric && ($isDimension || ($key !== 'account_type' && !in_array($key, $standardRelations) && !in_array($key, $dateFields) && !$this->_class->hasField($key)))) {
                    $dimAlias = "f_dim_" . preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $safeLeftJoin('e', 'dimension_set_items', "dsi_$dimAlias", "e.dimension_set_id = dsi_$dimAlias.dimension_set_id");
                    $safeLeftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");
                    $safeLeftJoin("dv_$dimAlias", 'dimension_keys', "dk_$dimAlias", "dv_$dimAlias.dimension_key_id = dk_$dimAlias.id AND dk_$dimAlias.name = :key_$dimAlias");
                    
                    $qb->setParameter("key_$dimAlias", $dimKey)
                       ->andWhere("dv_$dimAlias.value = :val_$dimAlias")
                       ->setParameter("val_$dimAlias", $value);
                } elseif ((str_ends_with($entityName, 'Metric') || $this->isChanneledMetric) && (isset(self::$relationMap[$key]) || $key === 'account_type')) {
                    $realKey = ($key === 'account_type') ? 'channeledAccount' : $key;
                    $map = self::$relationMap[$realKey];
                    $safeLeftJoin('mc', $map['table'], $map['alias'], "mc.{$map['fk']} = {$map['alias']}.id");
                    
                    $targetCol = ($key === 'account_type') ? 'type' : 'platform_id';
                    // If the value looks like a URL, use the 'url' field from the mapping (if defined) or platform_id
                    if (str_starts_with((string)$value, 'http')) {
                        $targetCol = $map['field']; // For page, this is 'url'
                    }

                    $isPlatformIdValue = is_numeric($value) && (float)$value > 2147483647;
                    $isPostgres = Helpers::isPostgres();
                    
                    if ($value === 'N/A') {
                        $sqlKey = (isset($map['fk'])) ? "mc.{$map['fk']}" : "mc.$key";
                        if ($key === 'page') $sqlKey = 'mc.page_id';
                        $qb->andWhere("$sqlKey IS NULL");
                    } else if ($value === 'NOT_NULL') {
                        $sqlKey = (isset($map['fk'])) ? "mc.{$map['fk']}" : "mc.$key";
                        if ($key === 'page') $sqlKey = 'mc.page_id';
                        $qb->andWhere("$sqlKey IS NOT NULL");
                    } else if (($isPlatformIdValue || str_starts_with((string)$value, 'http')) && ($targetCol === 'platform_id' || $targetCol === 'url')) {
                        $qb->andWhere("{$map['alias']}.$targetCol = :f_$key")
                           ->setParameter("f_$key", (string)$value);
                    } else {
                        if ($isPostgres) {
                            $condition = ($targetCol === 'type') ? "{$map['alias']}.type = :f_$key" : "(CAST(mc.{$map['fk']} AS text) = :f_$key OR {$map['alias']}.platform_id = :f_$key)";
                            $qb->andWhere($condition)
                               ->setParameter("f_$key", (string)$value);
                        } else {
                            $condition = ($targetCol === 'type') ? "{$map['alias']}.type = :f_$key" : "(mc.{$map['fk']} = :f_$key OR {$map['alias']}.platform_id = :f_$key)";
                            $qb->andWhere($condition)
                               ->setParameter("f_$key", $value);
                        }
                    }
                } else {
                    $sqlKey = $this->mapFieldToSql($key);
                    $paramName = 'f_' . preg_replace('/[^a-z0-9]/i', '_', $key);
                    
                    if ($value === 'N/A') {
                        $qb->andWhere("$sqlKey IS NULL");
                    } else if ($value === 'NOT_NULL') {
                        $qb->andWhere("$sqlKey IS NOT NULL");
                    } else {
                        $isPostgres = Helpers::isPostgres();
                        if ($isPostgres) {
                            // Cast both sides to text if the input is not numeric to prevent type mismatch
                            if (!is_numeric($value)) {
                                $qb->andWhere("CAST($sqlKey AS text) = :$paramName")
                                   ->setParameter($paramName, (string)$value);
                            } else {
                                $qb->andWhere("$sqlKey = :$paramName")
                                   ->setParameter($paramName, $value);
                            }
                        } else {
                            $qb->andWhere("$sqlKey = :$paramName")
                               ->setParameter($paramName, $value);
                        }
                    }
                }
            }
        }

        // Apply date filters using the correctly mapped column names
        if ($startDate || $endDate) {
            if ($this->isChanneledMetric || $isMetric) {
                $sqlDateField = 'mc.metric_date';
            } else {
                $dateField = 'platformCreatedAt';
                if (!$this->_class->hasField($dateField)) {
                    $dateField = $this->_class->hasField('createdAt') ? 'createdAt' : 'date';
                }
                $sqlDateField = $this->mapFieldToSql($dateField);
            }

            if ($startDate) {
                $qb->andWhere("$sqlDateField >= :startDate")
                   ->setParameter('startDate', $startDate);
            }
            if ($endDate) {
                $qb->andWhere("$sqlDateField <= :endDate")
                   ->setParameter('endDate', $endDate);
            }
        }

        // Apply ordering
        if ($orderBy) {
            $direction = (strtoupper($orderDir) === 'DESC') ? 'DESC' : 'ASC';
            $qb->orderBy($orderBy, $direction);
        }

        if (isset($_GET['debug_sql']) || php_sapi_name() === 'cli') {
            echo "==== DBAL DEBUG ====\nSQL:\n" . $qb->getSQL() . "\nParameters:\n";
            print_r($qb->getParameters());
            echo "====================\n";
        }
        
        $stmt = $qb->executeQuery();
        $results = $stmt->fetchAllAssociative();

        // 4. Smoothing: Fill temporal gaps for time-series data
        if ($startDate && $endDate) {
            $temporalField = null;
            $temporalType = null;
            foreach ($groupBy as $field) {
                if (in_array(strtolower($field), ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])) {
                    $temporalField = $field;
                    $temporalType = strtolower($field);
                    break;
                }
            }

            if ($temporalField) {
                $results = $this->fillTemporalGaps($results, $temporalField, $temporalType, $startDate, $endDate, $aggregations, $groupBy);
            }
        }

        return $results;
    }

    /**
     * Fills gaps in a time series result set with zeroed-out records.
     */
    protected function fillTemporalGaps(
        array $results,
        string $temporalField,
        string $type,
        string $startDate,
        string $endDate,
        array $aggregations,
        array $groupBy
    ): array {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $periods = [];
        
        // Generate all expected periods
        $current = clone $start;
        while ($current <= $end) {
            $periodKey = match($type) {
                'daily'     => $current->format('Y-m-d'),
                'weekly'    => $current->format('Y-\W') . str_pad($current->format('W'), 2, '0', STR_PAD_LEFT),
                'monthly'   => $current->format('Y-m'),
                'quarterly' => $current->format('Y-\Q') . ceil($current->format('n') / 3),
                'yearly'    => $current->format('Y'),
            };
            $periods[$periodKey] = true;
            
            $interval = match($type) {
                'daily'     => 'P1D',
                'weekly'    => 'P1W',
                'monthly'   => 'P1M',
                'quarterly' => 'P3M',
                'yearly'    => 'P1Y',
            };
            $current->add(new \DateInterval($interval));
        }

        // Identify non-temporal grouping fields
        $otherGroups = array_filter($groupBy, fn($f) => $f !== $temporalField);
        
        // If we have other groups (e.g. gender), we need to fill gaps for each combination
        if (!empty($otherGroups)) {
            $uniqueCombos = [];
            foreach ($results as $row) {
                $combo = [];
                foreach ($otherGroups as $field) {
                    // Casing defense (PostgreSQL returns lowercase even with quotes in some envs)
                    $val = $row[$field] ?? $row[strtolower($field)] ?? null;
                    $combo[$field] = $val;
                }
                $comboKey = serialize($combo);
                $uniqueCombos[$comboKey] = $combo;
            }

            $indexedResults = [];
            foreach ($results as $row) {
                $combo = [];
                foreach ($otherGroups as $field) {
                    $val = $row[$field] ?? $row[strtolower($field)] ?? null;
                    $combo[$field] = $val;
                }
                $temporalVal = $row[$temporalField] ?? $row[strtolower($temporalField)] ?? null;
                $key = $temporalVal . '|' . serialize($combo);
                $indexedResults[$key] = $row;
            }

            $finalResults = [];
            foreach ($uniqueCombos as $combo) {
                foreach (array_keys($periods) as $pKey) {
                    $lookupKey = $pKey . '|' . serialize($combo);
                    if (isset($indexedResults[$lookupKey])) {
                        $finalResults[] = $indexedResults[$lookupKey];
                    } else {
                        $newRow = array_merge($combo, [$temporalField => $pKey]);
                        foreach (array_keys($aggregations) as $alias) {
                            $newRow[$alias] = 0;
                        }
                        $finalResults[] = $newRow;
                    }
                }
            }
            return $finalResults;
        }

        // Simple case: only temporal grouping
        $indexedResults = [];
        foreach ($results as $row) {
            $temporalVal = $row[$temporalField] ?? $row[strtolower($temporalField)] ?? null;
            $indexedResults[$temporalVal] = $row;
        }

        $finalResults = [];
        foreach (array_keys($periods) as $pKey) {
            if (isset($indexedResults[$pKey])) {
                $finalResults[] = $indexedResults[$pKey];
            } else {
                $newRow = [$temporalField => $pKey];
                foreach (array_keys($aggregations) as $alias) {
                    $newRow[$alias] = 0;
                }
                $finalResults[] = $newRow;
            }
        }

        return $finalResults;
    }

    /**
     * Maps a framework field (e.g. metadata.clicks) to a SQL expression.
     */
    protected function mapFieldToSql(string $expr, bool $isAggregate = false): string
    {
        $field = trim($expr);            
        $lowerField = strtolower($field);

        // Specialized metric formulas for ChanneledMetric and Metric to handle cross-row aggregation
        $this->isChanneledMetric = str_ends_with($this->getEntityName(), 'ChanneledMetric');
        $isMetric = str_ends_with($this->getEntityName(), 'Analytics\Metric');
        
        if (($this->isChanneledMetric || $isMetric) && $isAggregate) {
            $valCol = $this->isChanneledMetric ? 'm.value' : 'e.value';
            $formulas = [
                'spend'       => "SUM(CASE WHEN mc.name IN ('spend', 'spend_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'clicks'      => "SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'impressions' => "SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily', 'post_impressions', 'post_impressions_daily', 'page_impressions', 'page_impressions_daily', 'page_media_view', 'post_media_view', 'views', 'views_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'reach'       => "SUM(CASE WHEN mc.name IN ('reach', 'reach_daily', 'post_reach', 'post_reach_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'frequency'   => "SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('reach', 'reach_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END), 0)",
                'ctr'         => "SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END), 0)",
                'cpc'         => "SUM(CASE WHEN mc.name IN ('spend', 'spend_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END), 0)",
                'cpm'         => "SUM(CASE WHEN mc.name IN ('spend', 'spend_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END) / (NULLIF(SUM(CASE WHEN mc.name IN ('impressions', 'impressions_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END), 0) / 1000)",
                'position'    => $this->needsImpressionsJoin ? 
                    "SUM(CASE WHEN mc.name = 'position' THEN $valCol * (SELECT m2.value FROM metrics m2 JOIN metric_configs mc2 ON m2.metric_config_id = mc2.id WHERE mc2.name IN ('impressions', 'page_media_view', 'post_media_view') AND mc2.metric_date = mc.metric_date AND mc2.channel = mc.channel AND (mc2.dimension_set_id <=> mc.dimension_set_id) AND (mc2.query_id <=> mc.query_id) AND (mc2.page_id <=> mc.page_id) LIMIT 1) ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('impressions', 'page_media_view', 'post_media_view') THEN $valCol ELSE 0 END), 0)" :
                    "NULL",
                'unique_clicks' => "SUM(CASE WHEN mc.name = 'unique_clicks' THEN $valCol ELSE 0 END)",
                'results'       => "SUM(CASE WHEN mc.name = 'results' THEN $valCol ELSE 0 END)",
                'cost_per_result' => "SUM(CASE WHEN mc.name = 'spend' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'results' THEN $valCol ELSE 0 END), 0)",
                'result_rate'     => "SUM(CASE WHEN mc.name = 'results' THEN $valCol ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name IN ('impressions', 'page_media_view', 'post_media_view') THEN $valCol ELSE 0 END), 0)",
                'roas'            => "AVG(CASE WHEN mc.name = 'purchase_roas' THEN $valCol ELSE NULL END)",
                'website_roas'    => "AVG(CASE WHEN mc.name = 'website_purchase_roas' THEN $valCol ELSE NULL END)",
                'actions'         => "SUM(CASE WHEN mc.name = 'actions' THEN $valCol ELSE 0 END)",
                'campaign_status' => "MIN(rcc.status)",
                'purchase_roas'   => "AVG(CASE WHEN mc.name = 'purchase_roas' THEN $valCol ELSE NULL END)",
                'website_purchase_roas' => "AVG(CASE WHEN mc.name = 'website_purchase_roas' THEN $valCol ELSE NULL END)",
                // Organic & Shared Metrics - Mapped for Unification
                // Intelligence: Detect period and apply SUM or DELTA (Current - Previous)
                'total_interactions' => "SUM(CASE WHEN mc.name IN ('total_interactions', 'total_interactions_daily', 'post_engagement', 'post_engagement_daily', 'page_post_engagements', 'page_post_engagements_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'profile_views'      => "SUM(CASE WHEN mc.name IN ('profile_views', 'profile_views_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'follower_count'     => "SUM(CASE WHEN mc.name IN ('follower_count', 'follower_count_daily', 'page_fans', 'page_fans_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'page_impressions'   => "SUM(CASE WHEN mc.name IN ('page_impressions', 'page_impressions_daily', 'page_media_view', 'page_media_view_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'page_post_engagements' => "SUM(CASE WHEN mc.name IN ('page_post_engagements', 'page_post_engagements_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'page_views_total'   => "SUM(CASE WHEN mc.name IN ('page_views_total', 'page_views_total_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'page_fans'          => "SUM(CASE WHEN mc.name IN ('page_fans', 'page_fans_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'post_impressions'   => "SUM(CASE WHEN mc.name IN ('post_impressions', 'post_impressions_daily', 'post_media_view', 'post_media_view_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'post_engagement'    => "SUM(CASE WHEN mc.name IN ('post_engagement', 'post_engagement_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'post_reactions_by_type_total' => "SUM(CASE WHEN mc.name IN ('post_reactions_by_type_total', 'post_reactions_by_type_total_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'likes'              => "SUM(CASE WHEN mc.name IN ('likes', 'likes_daily', 'post_reactions_by_type_total', 'post_reactions_by_type_total_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'comments'           => "SUM(CASE WHEN mc.name IN ('comments', 'comments_daily', 'post_comments', 'post_comments_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'shares'             => "SUM(CASE WHEN mc.name IN ('shares', 'shares_daily', 'post_shares', 'post_shares_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'saves'              => "SUM(CASE WHEN mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'saved'              => "SUM(CASE WHEN mc.name IN ('saves', 'saves_daily', 'saved', 'saved_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'plays'              => "SUM(CASE WHEN mc.name IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'views'              => "SUM(CASE WHEN mc.name IN ('plays', 'plays_daily', 'video_views', 'video_views_daily', 'views', 'views_daily', 'post_video_views', 'post_video_views_daily', 'page_video_views', 'page_video_views_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'replies'            => "SUM(CASE WHEN mc.name IN ('replies', 'replies_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'accounts_engaged'   => "SUM(CASE WHEN mc.name IN ('accounts_engaged', 'accounts_engaged_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'website_clicks'     => "SUM(CASE WHEN mc.name IN ('website_clicks', 'website_clicks_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'profile_links_taps' => "SUM(CASE WHEN mc.name IN ('profile_links_taps', 'profile_links_taps_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'follows_and_unfollows' => "SUM(CASE WHEN mc.name IN ('follows_and_unfollows', 'follows_and_unfollows_daily') AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                
                // Mappings for exact _daily metric fields (Post Level Content)
                'reach_daily'       => "SUM(CASE WHEN mc.name = 'reach_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'impressions_daily' => "SUM(CASE WHEN mc.name = 'impressions_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'likes_daily'       => "SUM(CASE WHEN mc.name = 'likes_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'comments_daily'    => "SUM(CASE WHEN mc.name = 'comments_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'shares_daily'      => "SUM(CASE WHEN mc.name = 'shares_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'saved_daily'       => "SUM(CASE WHEN mc.name = 'saved_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'total_interactions_daily' => "SUM(CASE WHEN mc.name = 'total_interactions_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
                'views_daily'       => "SUM(CASE WHEN mc.name = 'views_daily' AND mc.period = 'daily' THEN $valCol ELSE 0 END)",
            ];

            if (isset($formulas[$lowerField])) {
                return $formulas[$lowerField];
            }

            // Prevent direct 'value' aggregation for ChanneledMetric to avoid data corruption (summing different units)
            if (str_ends_with($this->getEntityName(), 'ChanneledMetric') && ($lowerField === 'value' || str_contains($lowerField, 'm.value'))) {
                throw new \InvalidArgumentException(
                    "Direct aggregation of 'value' field is restricted for ChanneledMetrics to prevent data corruption. " .
                    "Please use intelligent formulas (e.g., 'spend', 'clicks', 'ctr', 'cpc', 'cpm', 'frequency', 'position') " .
                    "or filter specifically by 'name' before aggregating."
                );
            }
        }

        // If it's an aggregate expression, it might contain functions, arithmetic and multiple fields.
        if ($isAggregate) {
            // Find all potential field references and map them while leaving functions and operators intact.
            $patterns = [
                '/metadata\.[a-zA-Z0-9_]+/',
                '/data\.[a-zA-Z0-9_]+/',
                '/metric\.[a-zA-Z0-9_]+/',
                '/metricConfig\.[a-zA-Z0-9_]+/',
                '/\b(id|name|period|metricDate|value|platformCreatedAt|createdAt|date)\b/'
            ];

            return preg_replace_callback($patterns, function ($matches) {
                return $this->mapFieldToSql($matches[0], false);
            }, $field);
        }

        // JSON extraction (metadata.field or data.field)
        if (str_starts_with($field, 'metadata.') || str_starts_with($field, 'data.')) {
            $isData = str_starts_with($field, 'data.');
            $path = substr($field, $isData ? 5 : 9);
            $source = $isData ? 'e.data' : 'e.metadata';
            
            if (!$isData && str_ends_with($this->getEntityName(), 'ChanneledMetric')) {
                $source = 'm.metadata';
            }
            $isPostgres = Helpers::isPostgres();
            if ($isPostgres) {
                return "$source->>'$path'";
            }
            return "JSON_UNQUOTE(JSON_EXTRACT($source, '$.$path'))";
        }

        // Relation metadata extraction (relationName.metadata.field)
        if (preg_match('/^([a-zA-Z0-9]+)\.(metadata|data)\.([a-zA-Z0-9_]+)$/', $field, $matches)) {
            $relName = $matches[1];
            $jsonField = $matches[2];
            $path = $matches[3];
            
            if (isset(self::$relationMap[$relName])) {
                $map = self::$relationMap[$relName];
                $source = $map['alias'] . '.' . $jsonField;
                
                $isPostgres = Helpers::isPostgres();
                if ($isPostgres) {
                    return "($source #>> '{$path}')";
                } else {
                    return "JSON_UNQUOTE(JSON_EXTRACT($source, '$.$path'))";
                }
            }
        }

        // Handle generic JSON extraction from relationMap
        if (isset(self::$relationMap[$lowerField]['isJSON']) && self::$relationMap[$lowerField]['isJSON']) {
             $map = self::$relationMap[$lowerField];
             $jsonPath = $map['jsonPath'] ?? '';
             if (Helpers::isPostgres()) {
                 return "COALESCE(({$map['alias']}.{$map['field']} #>> '{{$jsonPath}}'), 'N/A')";
             } else {
                 return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT({$map['alias']}.{$map['field']}, '$.$jsonPath')) AS CHAR), 'N/A')";
             }
        }

        // Relation mapping for metrics
        if (str_starts_with($field, 'metric.')) {
            return "m." . substr($field, 7);
        }
        if (str_starts_with($field, 'metricConfig.')) {
            $subField = substr($field, 13);
            if ($subField === 'metricDate') $subField = 'metric_date';
            return "mc." . $subField;
        }

        // Common aliasing for metricDate and name
        if ($field === 'metricDate') {
            return "mc.metric_date";
        }
        if ($field === 'name' || $field === 'period' || $field === 'channel') {
            return "mc.$field";
        }
        
        // Temporal virtual fields
        if ($this->isChanneledMetric) {
            $baseDate = 'mc.metric_date';
        } else {
            $baseDate = 'e.platform_created_at';
            if (!$this->_class->hasField('platformCreatedAt')) {
                 $baseDate = $this->_class->hasField('createdAt') ? 'e.created_at' : 'e.date';
            }
        }

        $isPostgres = Helpers::isPostgres();
        if ($isPostgres) {
            $dateParts = [
                'year'      => "EXTRACT(YEAR FROM $baseDate)",
                'month'     => "EXTRACT(MONTH FROM $baseDate)",
                'day'       => "EXTRACT(DAY FROM $baseDate)",
                'week'      => "EXTRACT(WEEK FROM $baseDate)",
                'quarter'   => "EXTRACT(QUARTER FROM $baseDate)",
                'dayofweek' => "EXTRACT(DOW FROM $baseDate)",
                'dayname'   => "TO_CHAR($baseDate, 'Day')",
                'monthname' => "TO_CHAR($baseDate, 'Month')",
                // Friendly grouping keys
                'daily'     => "TO_CHAR($baseDate, 'YYYY-MM-DD')",
                'weekly'    => "TO_CHAR($baseDate, 'IYYY-\"W\"IW')",
                'monthly'   => "TO_CHAR($baseDate, 'YYYY-MM')",
                'quarterly' => "CONCAT(EXTRACT(YEAR FROM $baseDate), '-Q', EXTRACT(QUARTER FROM $baseDate))",
                'yearly'    => "EXTRACT(YEAR FROM $baseDate)",
            ];
        } else {
            $dateParts = [
                'year'      => "YEAR($baseDate)",
                'month'     => "MONTH($baseDate)",
                'day'       => "DAY($baseDate)",
                'week'      => "WEEK($baseDate)",
                'quarter'   => "QUARTER($baseDate)",
                'dayofweek' => "DAYOFWEEK($baseDate)",
                'dayname'   => "DAYNAME($baseDate)",
                'monthname' => "MONTHNAME($baseDate)",
                // Friendly grouping keys
                'daily'     => "DATE($baseDate)",
                'weekly'    => "CONCAT(YEAR($baseDate), '-W', LPAD(WEEK($baseDate), 2, '0'))",
                'monthly'   => "CONCAT(YEAR($baseDate), '-', LPAD(MONTH($baseDate), 2, '0'))",
                'quarterly' => "CONCAT(YEAR($baseDate), '-Q', QUARTER($baseDate))",
                'yearly'    => "YEAR($baseDate)",
            ];
        }
        if (isset($dateParts[$lowerField])) {
            return $dateParts[$lowerField];
        }

        if ($field === 'value') {
            return str_ends_with($this->getEntityName(), 'Metric') ? (str_ends_with($this->getEntityName(), 'ChanneledMetric') ? 'm.value' : 'e.value') : "e.$field";
        }

        // Default: translate camelCase properties to snake_case columns
        if ($this->_class->hasField($field)) {
            return "e." . $this->_class->getColumnName($field);
        }

        return "e.$field";
    }

    /**
     * @param array $aggregations
     * @param array $groupBy
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildAggregateQuery(
        array $aggregations,
        array $groupBy = [],
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::AGGREGATE);

        // Build SELECT with aggregations
        foreach ($aggregations as $alias => $funcExpr) {
            // Basic parsing for simple DQL functions (SUM, AVG, etc)
            // Note: For complex JSON fields, we might need Native SQL or custom DQL functions.
            $query->addSelect("$funcExpr AS $alias");
        }

        // Build GROUP BY
        foreach ($groupBy as $field) {
            $query->addSelect("e.$field")->addGroupBy("e.$field");
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query;
    }

    /**
     * @param object|null $data
     * @param bool $returnEntity
     * @return Entity|array|null
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws OptimisticLockException
     */
    public function create(?object $data = null, bool $returnEntity = false): Entity|array|null
    {
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $entityName = $this->getEntityName();
                $entity = new $entityName();

                if ((array) $data) {
                    foreach ((array) $data as $key => $value) {
                        if (method_exists($entity, 'add' . Helpers::toCamelcase($key))) {
                            $entity->{'add' . Helpers::toCamelcase($key, true)}($value);
                        }
                    }
                }

                $this->getEntityManager()->persist($entity);
                $this->getEntityManager()->flush();

                return $this->read(
                    id: $entity->getId(),
                    returnEntity: $returnEntity,
                );
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount); // Backoff: 100ms, 200ms, 300ms
                    continue;
                }
                error_log("BaseRepository::create failed after $maxRetries retries: {$e->getMessage()}");
                throw $e;
            }
        }
        return null;
    }

    /**
     * @param int $id
     * @param bool $returnEntity
     * @param object|null $filters
     * @return Entity|array|null
     * @throws NonUniqueResultException
     * @throws Exception
     */
    public function read(int $id, bool $returnEntity = false, ?object $filters = null): Entity|array|null
    {
        $query = $this->buildReadQuery(id: $id, filters: $filters);

        $entity = $returnEntity
            ? $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT)
            : $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if (!$entity) {
            return null;
        }

        if (!is_array($entity)) {
            return $entity;
        }

        return $this->processResult(result: $entity);
    }

    /**
     * @param int $id
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildReadQuery(
        int $id,
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder()
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query;
    }

    /**
     * @return int
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getCount(): int
    {
        return $this->createBaseQueryBuilder(QueryBuilderType::COUNT)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param object|null $filters
     * @param string|null $startDate
     * @param string|null $endDate
     * @return int
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function countElements(
        ?object $filters = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int {
        $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        return $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param array|null $ids
     * @param object|null $filters
     * @param string $orderBy
     * @param string $orderDir
     * @param string|null $startDate
     * @param string|null $endDate
     * @return ArrayCollection
     * @throws Exception
     */
    public function readMultiple(
        int $limit = 100,
        int $pagination = 0,
        ?array $ids = null,
        ?object $filters = null,
        string $orderBy = 'id',
        string $orderDir = 'DESC',
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $extra = null
    ): ArrayCollection {
        // Fallback for repositories without ID fetch capability if needed, but not here
        $idQueryBuilder = $this->buildReadMultipleQuery(
            ids: $ids,
            filters: $filters,
            orderBy: $orderBy,
            orderDir: $orderDir,
            limit: $limit,
            pagination: $pagination,
            startDate: $startDate,
            endDate: $endDate,
            extra: $extra
        );

        // First step: Get ONLY the IDs we need, respecting limit and pagination
        $idResult = $idQueryBuilder->select('DISTINCT e.id AS id')->getQuery()->getScalarResult();
        $targetIds = array_column($idResult, 'id');

        if (empty($targetIds)) {
            return new ArrayCollection([]);
        }

        // Second step: Fetch full data for only these IDs, with all joins
        $dataQueryBuilder = $this->createBaseQueryBuilder()
            ->where('e.id IN (:targetIds)')
            ->setParameter('targetIds', $targetIds)
            ->orderBy("e.$orderBy", strtoupper($orderDir));

        $list = $dataQueryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_ARRAY);

        $processedList = array_map(
            fn ($item) => $this->processResult($item),
            $list
        );

        return new ArrayCollection($processedList);
    }

    /**
     * @param array|null $ids
     * @param object|null $filters
     * @param string $orderBy
     * @param string $orderDir
     * @param int $limit
     * @param int $pagination
     * @param string|null $startDate
     * @param string|null $endDate
     * @return QueryBuilder
     * @throws Exception
     */
    protected function buildReadMultipleQuery(
        ?array $ids,
        ?object $filters,
        string $orderBy,
        string $orderDir,
        int $limit,
        int $pagination,
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $extra = null
    ): QueryBuilder {
        $query = $this->createBaseQueryBuilder();

        if ($ids) {
            $query->where('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->andWhere('e.' . $key . ' = :' . $key)
                    ->setParameter($key, $value);
            }
        }

        $this->applyDateFilters($query, $startDate, $endDate);

        $query->orderBy("e.$orderBy", strtoupper($orderDir))
            ->setMaxResults($limit)
            ->setFirstResult($limit * $pagination);

        return $query;
    }

    /**
     * Apply date range filters if appropriate fields exist in the entity.
     *
     * @param QueryBuilder $query
     * @param string|null $startDate
     * @param string|null $endDate
     */
    protected function applyDateFilters(QueryBuilder $query, ?string $startDate, ?string $endDate): void
    {
        if (!$startDate && !$endDate) {
            return;
        }

        $dateField = null;
        if ($this->_class->hasField('platformCreatedAt')) {
            $dateField = 'platformCreatedAt';
        } elseif ($this->_class->hasField('createdAt')) {
            $dateField = 'createdAt';
        } elseif ($this->_class->hasField('date')) {
            $dateField = 'date';
        }

        if ($dateField) {
            if ($startDate) {
                $query->andWhere("e.$dateField >= :startDate")
                    ->setParameter('startDate', $startDate);
            }
            if ($endDate) {
                $query->andWhere("e.$dateField <= :endDate")
                    ->setParameter('endDate', $endDate);
            }
        }
    }

    /**
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        $result = $this->formatDates($result);
        return $this->applyHideFields($result);
    }

    /**
     * Recursive function to format all DateTimeInterface objects in an array.
     *
     * @param array $data
     * @param string $format
     * @return array
     */
    protected function formatDates(array $data, string $format = \DateTimeInterface::ATOM): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format($format);
            } elseif (is_array($value)) {
                $data[$key] = $this->formatDates($value, $format);
            }
        }
        return $data;
    }

    /**
     * @param int $id
     * @param object|null $data
     * @param bool $returnEntity
     * @return bool|array|Entity|null
     * @throws NonUniqueResultException
     */
    public function update(int $id, ?object $data = null, bool $returnEntity = false): bool|array|null|Entity
    {
        $entity = $this->_em->find($this->getEntityName(), $id);

        if (!$entity) {
            return false;
        }

        if ((array) $data) {
            foreach ((array) $data as $key => $value) {
                if (method_exists($entity, 'add' . Helpers::toCamelcase($key, true))) {
                    $entity->{'add' . Helpers::toCamelcase($key, true)}($value);
                }
            }
        }

        $entity->onPreUpdate();

        $this->_em->persist($entity);
        $this->_em->flush();

        return $this->read(
            id: $entity->getId(),
            returnEntity: $returnEntity,
        );
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $entity = $this->_em->find($this->getEntityName(), $id);

        if (!$entity) {
            return false;
        }

        $props = $this->_class->fieldMappings;

        foreach ($props as $key => $value) {
            if (is_a($entity->{'get' . Helpers::toCamelcase($key, true)}(), 'Collection')) {
                $entity->{'remove' . Helpers::toCamelcase($key, true)}($entity->{'get' . Helpers::toCamelcase($key, true)}());
            }
        }

        $this->_em->remove($entity);
        $this->_em->flush();

        return true;
    }
}
