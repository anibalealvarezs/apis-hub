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

        // Specialized logic for ChanneledMetric to support deep joins in aggregation
        $this->activeAggregateJoins = [];
        $this->isChanneledMetric = str_ends_with($this->getEntityName(), 'ChanneledMetric');
        if ($this->isChanneledMetric) {
            $qb->join('e', 'metrics', 'm', 'e.metric_id = m.id')
               ->join('m', 'metric_configs', 'mc', 'm.metric_config_id = mc.id');
            $this->activeAggregateJoins['m'] = true;
            $this->activeAggregateJoins['mc'] = true;
        }

        // Selects with aggregation functions
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

        $relationMap = [
            'query'    => ['table' => 'queries', 'fk' => 'query_id', 'field' => 'query', 'alias' => 'rq'],
            'page'     => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'url', 'alias' => 'rp'],
            'account'  => ['table' => 'accounts', 'fk' => 'account_id', 'field' => 'name', 'alias' => 'ra'],
            'campaign' => ['table' => 'campaigns', 'fk' => 'campaign_id', 'field' => 'name', 'alias' => 'rc'],
            'channeledAccount'  => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'name', 'alias' => 'rca'],
            'channeledCampaign' => ['table' => 'channeled_campaigns', 'fk' => 'channeled_campaign_id', 'field' => 'name', 'alias' => 'rcc'],
            'adGroup'  => ['table' => 'channeled_ad_groups', 'fk' => 'channeled_ad_group_id', 'field' => 'name', 'alias' => 'rag'],
            'ad'       => ['table' => 'channeled_ads', 'fk' => 'channeled_ad_id', 'field' => 'name', 'alias' => 'rad'],
            'country'  => ['table' => 'countries', 'fk' => 'country_id', 'field' => 'name', 'alias' => 'rcty'],
            'device'   => ['table' => 'devices', 'fk' => 'device_id', 'field' => 'name', 'alias' => 'rd'],
        ];
        $activeJoins = [];

        $standardRelations = array_keys($relationMap);
        $dateFields = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'year', 'month', 'day', 'week', 'quarter', 'dayofweek', 'dayname', 'monthname', 'metricDate', 'platformCreatedAt', 'createdAt', 'date'];

        // Grouping and dimension handling
        foreach ($groupBy as $field) {
            $quotedField = $field;
            $isDimension = str_starts_with($field, 'dimensions.');
            $dimKey = $isDimension ? substr($field, 11) : $field;

            // Automatic dimension detection: if it's a ChanneledMetric and not a standard relation/date/field
            if ($this->isChanneledMetric && ($isDimension || (!in_array($field, $standardRelations) && !in_array($field, $dateFields) && !$this->_class->hasField($field)))) {
                $dimAlias = "dim_" . preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                // Join chain: channeled_metrics (e) -> dimension_set_items (dsi) -> dimension_values (dv) -> dimension_keys (dk)
                $qb->leftJoin('e', 'dimension_set_items', "dsi_$dimAlias", "e.dimension_set_id = dsi_$dimAlias.dimension_set_id")
                   ->leftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id")
                   ->leftJoin("dv_$dimAlias", 'dimension_keys', "dk_$dimAlias", "dv_$dimAlias.dimension_key_id = dk_$dimAlias.id AND dk_$dimAlias.name = :key_$dimAlias")
                   ->setParameter("key_$dimAlias", $dimKey)
                   ->addSelect("dv_$dimAlias.value AS $quotedField")
                   ->addGroupBy("dv_$dimAlias.value");
            } elseif ($this->isChanneledMetric && in_array($field, ['account', 'campaign'])) {
                $isAccount = $field === 'account';
                $genericKey = $isAccount ? 'account' : 'campaign';
                $channeledKey = $isAccount ? 'channeledAccount' : 'channeledCampaign';
                $genericMap = $relationMap[$genericKey];
                $channeledMap = $relationMap[$channeledKey];
                
            } elseif ($this->isChanneledMetric && in_array($field, ['account', 'campaign'])) {
                $isAccount = $field === 'account';
                $genericKey = $isAccount ? 'account' : 'campaign';
                $channeledKey = $isAccount ? 'channeledAccount' : 'channeledCampaign';
                $genericMap = $relationMap[$genericKey];
                $channeledMap = $relationMap[$channeledKey];
                
                // Definitive Join Guard via QueryBuilder introspection
                $currentJoins = $qb->getQueryPart('join');
                $registeredAliases = [];
                foreach ($currentJoins as $fromAlias => $joins) {
                    foreach ($joins as $join) { $registeredAliases[$join['joinAlias']] = true; }
                }

                if (!isset($registeredAliases[$genericMap['alias']])) {
                    $qb->leftJoin('mc', $genericMap['table'], $genericMap['alias'], "mc.{$genericMap['fk']} = {$genericMap['alias']}.id");
                    $registeredAliases[$genericMap['alias']] = true;
                }
                if (!isset($registeredAliases[$channeledMap['alias']])) {
                    $qb->leftJoin('mc', $channeledMap['table'], $channeledMap['alias'], "mc.{$channeledMap['fk']} = {$channeledMap['alias']}.id");
                    $registeredAliases[$channeledMap['alias']] = true;
                }

                if ($isAccount) {
                    // Advanced fallback: try to find account via campaign if direct link is missing
                    $campaignMap = $relationMap['channeledCampaign'];
                    if (!isset($registeredAliases[$campaignMap['alias']])) {
                        $qb->leftJoin('mc', $campaignMap['table'], $campaignMap['alias'], "mc.{$campaignMap['fk']} = {$campaignMap['alias']}.id");
                        $registeredAliases[$campaignMap['alias']] = true;
                    }
                    if (!isset($registeredAliases['rca_fallback'])) {
                        $qb->leftJoin($campaignMap['alias'], 'channeled_accounts', 'rca_fallback', "{$campaignMap['alias']}.channeled_account_id = rca_fallback.id");
                        $registeredAliases['rca_fallback'] = true;
                    }
                    
                    $qb->addSelect("COALESCE({$channeledMap['alias']}.{$channeledMap['field']}, rca_fallback.name, {$genericMap['alias']}.{$genericMap['field']}, CAST(mc.{$channeledMap['fk']} AS CHAR), CAST(mc.{$genericMap['fk']} AS CHAR), 'Unknown') AS $quotedField")
                       ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                       ->addGroupBy("rca_fallback.name")
                       ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                       ->addGroupBy("mc.{$channeledMap['fk']}")
                       ->addGroupBy("mc.{$genericMap['fk']}");
                } else {
                    $qb->addSelect("COALESCE({$channeledMap['alias']}.{$channeledMap['field']}, {$genericMap['alias']}.{$genericMap['field']}, CAST(mc.{$channeledMap['fk']} AS CHAR), CAST(mc.{$genericMap['fk']} AS CHAR), 'Unknown') AS $quotedField")
                       ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                       ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                       ->addGroupBy("mc.{$channeledMap['fk']}")
                       ->addGroupBy("mc.{$genericMap['fk']}");
                }
            } elseif ($this->isChanneledMetric && isset($relationMap[$field])) {
                $map = $relationMap[$field];
                $currentJoins = $qb->getQueryPart('join');
                $registeredAliases = [];
                foreach ($currentJoins as $fromAlias => $joins) {
                    foreach ($joins as $join) { $registeredAliases[$join['joinAlias']] = true; }
                }

                if (!isset($registeredAliases[$map['alias']])) {
                    $qb->leftJoin('mc', $map['table'], $map['alias'], "mc.{$map['fk']} = {$map['alias']}.id");
                }
                $qb->addSelect("COALESCE({$map['alias']}.{$map['field']}, CAST(mc.{$map['fk']} AS CHAR), 'Unknown') AS $quotedField")
                   ->addGroupBy("{$map['alias']}.{$map['field']}")
                   ->addGroupBy("mc.{$map['fk']}");
            } else {
                $sqlField = $this->mapFieldToSql($field);
                $qb->addSelect("$sqlField AS $quotedField")->addGroupBy($sqlField);
            }
        }

        // Apply filters
        if ($filters) {
            foreach ($filters as $key => $value) {
                $isDimension = str_starts_with($key, 'dimensions.');
                $dimKey = $isDimension ? substr($key, 11) : $key;

                if ($this->isChanneledMetric && ($isDimension || (!in_array($key, $standardRelations) && !in_array($key, $dateFields) && !$this->_class->hasField($key)))) {
                    $dimAlias = "f_dim_" . preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $qb->join('e', 'dimension_set_items', "dsi_$dimAlias", "e.dimension_set_id = dsi_$dimAlias.dimension_set_id")
                       ->join("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id")
                       ->join("dv_$dimAlias", 'dimension_keys', "dk_$dimAlias", "dv_$dimAlias.dimension_key_id = dk_$dimAlias.id AND dk_$dimAlias.name = :key_$dimAlias")
                       ->setParameter("key_$dimAlias", $dimKey)
                       ->andWhere("dv_$dimAlias.value = :val_$dimAlias")
                       ->setParameter("val_$dimAlias", $value);
                } elseif ($this->isChanneledMetric && isset($relationMap[$key])) {
                    $map = $relationMap[$key];
                    $currentJoins = $qb->getQueryPart('join');
                    $registeredAliases = [];
                    foreach ($currentJoins as $fromAlias => $joins) {
                        foreach ($joins as $join) { $registeredAliases[$join['joinAlias']] = true; }
                    }

                    if (!isset($registeredAliases[$map['alias']])) {
                        $qb->leftJoin('mc', $map['table'], $map['alias'], "mc.{$map['fk']} = {$map['alias']}.id");
                    }
                    $qb->andWhere("{$map['alias']}.{$map['field']} = :f_$key")
                       ->setParameter("f_$key", $value);
                } else {
                    $sqlKey = $this->mapFieldToSql($key);
                    $paramName = 'f_' . preg_replace('/[^a-z0-9]/i', '_', $key);
                    $qb->andWhere("$sqlKey = :$paramName")
                       ->setParameter($paramName, $value);
                }
            }
        }

        // Apply date filters using the correctly mapped column names
        if ($startDate || $endDate) {
            $dateField = 'platformCreatedAt'; // Default for channeled entities
            if (!$this->_class->hasField($dateField)) {
                $dateField = $this->_class->hasField('createdAt') ? 'createdAt' : 'date';
            }
            $sqlDateField = $this->mapFieldToSql($dateField);

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

        $results = $qb->executeQuery()->fetchAllAssociative();

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
                    $combo[$field] = $row[$field] ?? null;
                }
                $comboKey = serialize($combo);
                $uniqueCombos[$comboKey] = $combo;
            }

            $indexedResults = [];
            foreach ($results as $row) {
                $combo = [];
                foreach ($otherGroups as $field) {
                    $combo[$field] = $row[$field] ?? null;
                }
                $key = $row[$temporalField] . '|' . serialize($combo);
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
            $indexedResults[$row[$temporalField]] = $row;
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

        // Specialized metric formulas for ChanneledMetric to handle cross-row aggregation
        $this->isChanneledMetric = str_ends_with($this->getEntityName(), 'ChanneledMetric');
        if ($this->isChanneledMetric && $isAggregate) {
            $formulas = [
                'spend'       => "SUM(CASE WHEN mc.name = 'spend' THEN m.value ELSE 0 END)",
                'clicks'      => "SUM(CASE WHEN mc.name = 'clicks' THEN m.value ELSE 0 END)",
                'impressions' => "SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END)",
                'reach'       => "SUM(CASE WHEN mc.name = 'reach' THEN m.value ELSE 0 END)",
                'frequency'   => "SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'reach' THEN m.value ELSE 0 END), 0)",
                'ctr'         => "SUM(CASE WHEN mc.name = 'clicks' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END), 0)",
                'cpc'         => "SUM(CASE WHEN mc.name = 'spend' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'clicks' THEN m.value ELSE 0 END), 0)",
                'cpm'         => "SUM(CASE WHEN mc.name = 'spend' THEN m.value ELSE 0 END) / (NULLIF(SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END), 0) / 1000)",
                'position'    => "SUM(CASE WHEN mc.name = 'position' THEN m.value ELSE 0 END * (
                    SELECT m2.value 
                    FROM metrics m2 
                    JOIN metric_configs mc2 ON m2.metric_config_id = mc2.id 
                    WHERE mc2.name = 'impressions' 
                    AND mc2.metric_date = mc.metric_date 
                    AND mc2.channel = mc.channel
                    AND (mc2.query_id = mc.query_id OR (mc2.query_id IS NULL AND mc.query_id IS NULL))
                    AND (mc2.page_id = mc.page_id OR (mc2.page_id IS NULL AND mc.page_id IS NULL))
                    LIMIT 1
                )) / NULLIF(SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END), 0)",
                'unique_clicks' => "SUM(CASE WHEN mc.name = 'unique_clicks' THEN m.value ELSE 0 END)",
                'results'       => "SUM(CASE WHEN mc.name = 'results' THEN m.value ELSE 0 END)",
                'cost_per_result' => "SUM(CASE WHEN mc.name = 'spend' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'results' THEN m.value ELSE 0 END), 0)",
                'result_rate'     => "SUM(CASE WHEN mc.name = 'results' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN mc.name = 'impressions' THEN m.value ELSE 0 END), 0)",
                'roas'            => "AVG(CASE WHEN mc.name = 'purchase_roas' THEN m.value ELSE NULL END)",
                'website_roas'    => "AVG(CASE WHEN mc.name = 'website_purchase_roas' THEN m.value ELSE NULL END)",
                'actions'         => "SUM(CASE WHEN mc.name = 'actions' THEN m.value ELSE 0 END)",
                'purchase_roas'   => "AVG(CASE WHEN mc.name = 'purchase_roas' THEN m.value ELSE NULL END)",
                'website_purchase_roas' => "AVG(CASE WHEN mc.name = 'website_purchase_roas' THEN m.value ELSE NULL END)",
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
        if ($field === 'name' || $field === 'period') {
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
