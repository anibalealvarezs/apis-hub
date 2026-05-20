<?php

    namespace Repositories;

    use Anibalealvarezs\ApiDriverCore\Classes\RepositoryRegistry;
    use Doctrine\Common\Collections\ArrayCollection;
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\AbstractQuery;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Exception\ORMException;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\NonUniqueResultException;
    use Doctrine\ORM\NoResultException;
    use Doctrine\ORM\OptimisticLockException;
    use Doctrine\ORM\QueryBuilder;
    use Doctrine\Persistence\Mapping\MappingException;
    use Entities\Entity;
    use Entities\Analytics\Channel as ChannelEntity;
    use Enums\QueryBuilderType;
    use Exception;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use InvalidArgumentException;
    use ReflectionException;
    use Services\Aggregation\AggregationExecutionResult;
    use Services\Aggregation\AggregationExecutor;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CompanionTimeWeightedAverageFormulaBuilder;
    use Services\Aggregation\DateSqlFieldResolver;
    use Services\Aggregation\FilterConditionResolver;
    use Services\Aggregation\LegacyAggregateExecutionContext;
    use Services\Aggregation\MetricDefaultFormulaBuilder;
    use Services\Aggregation\MetricPeriodConditionSqlResolver;
    use Services\Aggregation\SnapshotAggregateMetaExtractor;
    use Services\Aggregation\TemporalDatePartSqlResolver;
    use Services\Aggregation\TemporalGapFiller;
    use Services\Aggregation\Stages\LegacyAggregateDateStage;
    use Services\Aggregation\Stages\LegacyAggregateFinalizeStage;
    use Services\Aggregation\Stages\LegacyAggregateFilterStage;
    use Services\Aggregation\Stages\LegacyAggregateGroupingStage;
    use Services\Aggregation\Stages\LegacyAggregateOrderingStage;
    use Services\Aggregation\Stages\LegacyAggregateRelationContextStage;
    use Services\Aggregation\Stages\LegacyAggregateScopeStage;
    use Services\Aggregation\Stages\LegacyAggregateSelectStage;
    use stdClass;
    use Traits\OptimizedAggregationHelpersTrait;
    use Services\Aggregation\Strategies\WeightedMetricStrategy;
    use Services\Aggregation\Strategies\SocialOrganicStrategy;
    use Services\Aggregation\Strategies\MarketingHierarchyStrategy;

    class BaseRepository extends EntityRepository
    {
        use OptimizedAggregationHelpersTrait;

        // Doctrine ORM 3 no longer exposes these internals on EntityRepository,
        // but many repositories in this codebase still rely on them.
        protected EntityManagerInterface $_em;
        protected ClassMetadata $_class;

        public function __construct(EntityManagerInterface $em, ClassMetadata $class)
        {
            parent::__construct($em, $class);
            $this->_em = $em;
            $this->_class = $class;
        }

        /**
         * Provide access to the EntityManager for legacy code and tests.
         *
         * @return EntityManagerInterface
         */
        public function getEntityManager(): EntityManagerInterface
        {
            return $this->_em;
        }

        /**
         * Resolve a channel integer ID to its name string.
         * Uses the injected EntityManager so it works correctly in unit tests.
         *
         * @param int|string $channelId
         * @return string
         */
        protected function resolveChannelName(int|string $channelId): string
        {
            $repo = $this->_em->getRepository(ChannelEntity::class);
            if (is_int($channelId) || ctype_digit((string)$channelId)) {
                $channel = $repo->find((int)$channelId);
            } else {
                $channel = $repo->findOneBy(['name' => $channelId]);
            }
            return $channel ? $channel->getName() : (string)$channelId;
        }

        /**
         * List of top-level result fields to strip before returning the response.
         * Set via setHideFields() from the controller layer.
         */
        private array $hideFields = [];
        protected bool $isChanneledMetric = false;
        private array $activeAggregateJoins = [];
        private bool $needsImpressionsJoin = false;
        private ?string $aggregateRequestedPeriod = null;
        private bool $aggregateUseSnapshotDelta = false;
        private string $aggregateSnapshotFallbackMode = 'resilient';
        private array $lastAggregateMeta = [];
        protected static array $defaultRelationMap = [
            'query'                => ['table' => 'queries', 'fk' => 'query_id', 'field' => 'query', 'alias' => 'rq'],
            'channeledAccount'     => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'name', 'alias' => 'rca'],
            'account'              => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'name', 'alias' => 'rca'],
            'page'                 => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'url', 'alias' => 'rpa'],
            'campaign'             => ['table' => 'channeled_campaigns', 'fk' => 'channeled_campaign_id', 'field' => 'data', 'alias' => 'rcc', 'isJSON' => true, 'jsonPath' => 'name'],
            'channeled_account_id' => ['table' => 'channeled_accounts', 'fk' => 'channeled_account_id', 'field' => 'id', 'alias' => 'rca'],
            'channeledCampaign'    => ['table' => 'channeled_campaigns', 'fk' => 'channeled_campaign_id', 'field' => 'data', 'alias' => 'rcc', 'isJSON' => true, 'jsonPath' => 'name'],
            'adGroup'              => ['table' => 'channeled_ad_groups', 'fk' => 'channeled_ad_group_id', 'field' => 'name', 'alias' => 'rag'],
            'ad'                   => ['table' => 'channeled_ads', 'fk' => 'channeled_ad_id', 'field' => 'name', 'alias' => 'rad'],
            'creative'             => ['table' => 'creatives', 'fk' => 'creative_id', 'field' => 'name', 'alias' => 'rcre'],
            'country'              => ['table' => 'countries', 'fk' => 'country_id', 'field' => 'name', 'alias' => 'rcty'],
            'device'               => ['table' => 'devices', 'fk' => 'device_id', 'field' => 'type', 'alias' => 'rd'],
            'page_title'           => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'title', 'alias' => 'rpa', 'isAttribute' => true],
            'page_platform_id'     => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'platform_id', 'alias' => 'rpa', 'isAttribute' => true],
        ];

        /**
         * Get relations map (includes defaults and dynamically registered).
         */
        public static function getRelationMap(): array
        {
            return array_merge(self::$defaultRelationMap, RepositoryRegistry::getRelations());
        }

        /**
         * Get registered formulas.
         */
        protected static function getFormulas(): array
        {
            return RepositoryRegistry::getFormulas();
        }

        /**
         * @deprecated Use RepositoryRegistry::registerRelation()
         */
        public static function registerRelation(string $key, array $mapping): void
        {
            RepositoryRegistry::registerRelation($key, $mapping);
        }

        /**
         * @deprecated Use RepositoryRegistry::registerRelations()
         */
        public static function registerRelations(array $relations): void
        {
            RepositoryRegistry::registerRelations($relations);
        }

        /**
         * @deprecated Use RepositoryRegistry::registerFormula()
         */
        public static function registerFormula(string $name, string|callable $formula): void
        {
            RepositoryRegistry::registerFormula($name, $formula);
        }

        /**
         * @deprecated Use RepositoryRegistry::registerFormulas()
         */
        public static function registerFormulas(array $formulas): void
        {
            RepositoryRegistry::registerFormulas($formulas);
        }

        /**
         * Get the minimum date available for these metrics.
         */
        public function getMinDate(array|stdClass $filters = []): ?string
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
        public function getMaxDate(array|stdClass $filters = []): ?string
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
         * @param string|null $orderBy
         * @param string|null $orderDir
         * @return array
         * @throws ConfigurationException|\Doctrine\DBAL\Exception
         */
        public function aggregate(
            array   $aggregations,
            array   $groupBy = [],
            ?object $filters = null,
            ?string $startDate = null,
            ?string $endDate = null,
            ?string $orderBy = null,
            ?string $orderDir = 'ASC'
        ): array
        {
            $this->initializeAggregateExecutionContext($filters, $startDate, $endDate);

            $executionResult = $this->createAggregationExecutor()->executeAggregate(
                repository: $this,
                aggregations: $aggregations,
                groupBy: $groupBy,
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
                orderBy: $orderBy,
                orderDir: $orderDir,
            );
            $this->appendAggregateMeta($executionResult->getMeta());

            return $executionResult->getRows();
        }

        protected function createAggregationExecutor(): AggregationExecutor
        {
            return new AggregationExecutor();
        }

        protected function initializeAggregateExecutionContext(?object $filters, ?string $startDate, ?string $endDate): void
        {
            $this->aggregateRequestedPeriod = null;
            $this->aggregateUseSnapshotDelta = false;
            $this->aggregateSnapshotFallbackMode = 'resilient';
            $this->lastAggregateMeta = [];
            $this->activeAggregateJoins = [];

            $entityName = $this->getEntityName();
            $this->isChanneledMetric = str_ends_with($entityName, 'ChanneledMetric');

            if ($filters !== null && isset($filters->period) && is_string($filters->period) && trim($filters->period) !== '') {
                $this->aggregateRequestedPeriod = strtolower(trim($filters->period));
            }

            if ($filters !== null && isset($filters->snapshot_fallback_mode) && is_string($filters->snapshot_fallback_mode)) {
                $mode = strtolower(trim($filters->snapshot_fallback_mode));
                if (in_array($mode, ['strict', 'resilient'], true)) {
                    $this->aggregateSnapshotFallbackMode = $mode;
                }
            }

            $snapshotDelta = false;
            if ($filters && isset($filters->snapshot_delta)) {
                $snapshotDelta = filter_var($filters->snapshot_delta, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($snapshotDelta === null) {
                    $snapshotDelta = (bool)$filters->snapshot_delta;
                }
            }

            if ($snapshotDelta) {
                if (!$this->isChanneledMetric) {
                    throw new InvalidArgumentException('snapshot_delta is only supported for channeled metric aggregates.');
                }
                if (!$startDate || !$endDate) {
                    throw new InvalidArgumentException('snapshot_delta requires both startDate and endDate.');
                }

                $this->aggregateUseSnapshotDelta = true;
            }
        }

        /**
         * @param array<string, mixed> $meta
         */
        protected function appendAggregateMeta(array $meta): void
        {
            $this->lastAggregateMeta = array_merge($this->lastAggregateMeta, $meta);
        }

        /**
         * Public strategy hook for non-invasive metadata enrichment.
         *
         * @param array<string, mixed> $meta
         */
        public function appendOptimizedStrategyMeta(array $meta): void
        {
            $this->appendAggregateMeta($meta);
        }

        /**
         * @param array<int, array<string, mixed>> $rows
         * @param array<string, mixed> $meta
         */
        protected function buildAggregateExecutionResult(array $rows, array $meta = []): AggregationExecutionResult
        {
            $this->appendAggregateMeta($meta);

            return new AggregationExecutionResult($rows, $this->lastAggregateMeta);
        }

        /**
         * @throws ConfigurationException
         * @throws \Doctrine\DBAL\Exception
         */
        public function executeOptimizedAggregationPlan(AggregationPlan $plan): ?AggregationExecutionResult
        {
            $connection = $this->_em->getConnection();
            $context = $plan->getContext();
            $isPostgres = (bool)($context['is_postgres'] ?? Helpers::isPostgres());

            $strategies = [
                new WeightedMetricStrategy(),
                new SocialOrganicStrategy(),
                new MarketingHierarchyStrategy(),
            ];

            $candidateKeys = $plan->getCandidateOptimizedStrategies();

            foreach ($strategies as $strategy) {
                if (in_array($strategy->getKey(), $candidateKeys, true)) {
                    $rows = $strategy->execute($connection, $plan, $isPostgres);
                    if ($rows !== null) {
                        return $this->buildAggregateExecutionResult($rows, [
                            'execution_path'     => 'optimized',
                            'optimized_strategy' => $strategy->getKey(),
                        ]);
                    }
                }

                // Special case for sub-strategies of FB Organic
                if ($strategy->getKey() === 'social_organic') {
                    if (array_intersect(['social_organic_page_summary', 'social_organic_linked_pages', 'social_organic_post_snapshot'], $candidateKeys)) {
                        $rows = $strategy->execute($connection, $plan, $isPostgres);
                        if ($rows !== null) {
                            return $this->buildAggregateExecutionResult($rows, [
                                'execution_path'     => 'optimized',
                                'optimized_strategy' => 'social_organic',
                            ]);
                        }
                    }
                }
            }

            return null;
        }

        public function executeLegacyAggregationPlan(AggregationPlan $plan, ?string $fallbackReason = null): AggregationExecutionResult
        {
            $connection = $this->_em->getConnection();
            $qb = $this->createLegacyAggregateQueryBuilder($connection);
            $context = $this->createLegacyExecutionContext($qb, $plan);
            $results = $this->runLegacyAggregatePipeline($context);

            return $this->buildAggregateExecutionResult($results, [
                'execution_path'  => 'legacy',
                'fallback_reason' => $fallbackReason,
            ]);
        }

        /**
         * Phase 1 closure: keep legacy execution as stage-driven orchestration.
         *
         * @param LegacyAggregateExecutionContext $context
         * @return array<int, array<string, mixed>>
         * @throws ConfigurationException
         * @throws \Doctrine\DBAL\Exception
         */
        protected function runLegacyAggregatePipeline(LegacyAggregateExecutionContext $context): array
        {
            $this->applyLegacyAggregateScopeJoins($context);
            $this->applyLegacyAggregateSelects($context);
            $context = $context->withRelationContext($this->createLegacyAggregateRelationContext($context));
            $this->applyLegacyAggregateGrouping($context);
            $this->applyLegacyAggregateFilters($context);
            $this->applyLegacyAggregateDateConstraints($context);
            $this->applyLegacyAggregateOrdering($context);

            return $this->finalizeLegacyAggregateRows($context);
        }

        protected function createLegacyAggregateQueryBuilder(Connection $connection): \Doctrine\DBAL\Query\QueryBuilder
        {
            $qb = $connection->createQueryBuilder();
            $qb->from($this->_class->getTableName(), 'e');

            return $qb;
        }

        /**
         * @throws ConfigurationException
         */
        protected function createLegacyExecutionContext(\Doctrine\DBAL\Query\QueryBuilder $qb, AggregationPlan $plan): LegacyAggregateExecutionContext
        {
            $entityName = (string)$plan->getContextValue('entity_name', $this->getEntityName());

            return new LegacyAggregateExecutionContext(
                queryBuilder: $qb,
                plan: $plan,
                aggregations: $plan->getAggregations(),
                filters: $plan->getFilters(),
                startDate: $plan->getStartDate(),
                endDate: $plan->getEndDate(),
                groupBy: $plan->getStageValue('grouping', 'group_by', $plan->getGroupBy()),
                orderBy: $plan->getStageValue('post', 'order_by', $plan->getOrderBy()),
                orderDir: $plan->getStageValue('post', 'order_dir', $plan->getOrderDir()),
                entityName: $entityName,
                isMetric: (bool)$plan->getContextValue('is_metric', str_ends_with($entityName, 'Analytics\Metric')),
                isPostgres: (bool)$plan->getContextValue('is_postgres', Helpers::isPostgres()),
            );
        }

        protected function applyLegacyAggregateScopeJoins(LegacyAggregateExecutionContext $context): void
        {
            $activeAggregateJoins = &$this->activeAggregateJoins;
            (new LegacyAggregateScopeStage())->apply(
                context: $context,
                isChanneledMetric: $this->isChanneledMetric,
                activeAggregateJoins: $activeAggregateJoins,
            );
        }

        /**
         * @param LegacyAggregateExecutionContext $context
         * @throws ConfigurationException
         */
        protected function applyLegacyAggregateSelects(LegacyAggregateExecutionContext $context): void
        {
            $this->needsImpressionsJoin = (new LegacyAggregateSelectStage())->apply(
                context: $context,
                isChanneledMetric: $this->isChanneledMetric,
                mapFieldToSql: fn(string $expr, bool $isAggregate = false): string => $this->mapFieldToSql($expr, $isAggregate),
                resolveWeightedAggregationStrategies: fn(array $aggregations): array => $this->resolveWeightedAggregationStrategies($aggregations),
            );
        }

        /**
         * @return array<string, mixed>
         */
        protected function createLegacyAggregateRelationContext(LegacyAggregateExecutionContext $context): array
        {
            $relationMap = self::getRelationMap();
            $activeAggregateJoins = &$this->activeAggregateJoins;

            return (new LegacyAggregateRelationContextStage())->apply(
                context: $context,
                qb: $context->getQueryBuilder(),
                relationMap: $relationMap,
                isChanneledMetric: $this->isChanneledMetric,
                activeAggregateJoins: $activeAggregateJoins,
            );
        }

        /**
         * @param LegacyAggregateExecutionContext $context
         */
        protected function applyLegacyAggregateGrouping(LegacyAggregateExecutionContext $context): void
        {
            (new LegacyAggregateGroupingStage())->apply(
                context: $context,
                relationMap: self::getRelationMap(),
                isChanneledMetric: $this->isChanneledMetric,
                mapFieldToSql: fn(string $expr, bool $isAggregate = false): string => $this->mapFieldToSql($expr, $isAggregate),
                hasEntityField: fn(string $field): bool => $this->_class->hasField($field),
            );
        }

        /**
         * @param LegacyAggregateExecutionContext $context
         */
        protected function applyLegacyAggregateFilters(LegacyAggregateExecutionContext $context): void
        {
            (new LegacyAggregateFilterStage())->apply(
                context: $context,
                relationMap: self::getRelationMap(),
                isChanneledMetric: $this->isChanneledMetric,
                mapFieldToSql: fn(string $expr, bool $isAggregate = false): string => $this->mapFieldToSql($expr, $isAggregate),
                resolveFilterCondition: fn(mixed $rawValue): array => $this->resolveFilterCondition($rawValue),
                hasEntityField: fn(string $field): bool => $this->_class->hasField($field),
            );
        }

        protected function applyLegacyAggregateDateConstraints(LegacyAggregateExecutionContext $context): void
        {
            (new LegacyAggregateDateStage())->apply(
                context: $context,
                isChanneledMetric: $this->isChanneledMetric,
                aggregateUseSnapshotDelta: $this->aggregateUseSnapshotDelta,
                aggregateSnapshotFallbackMode: $this->aggregateSnapshotFallbackMode,
                mapFieldToSql: fn(string $expr, bool $isAggregate = false): string => $this->mapFieldToSql($expr, $isAggregate),
                hasEntityField: fn(string $field): bool => $this->_class->hasField($field),
            );
        }

        protected function applyLegacyAggregateOrdering(LegacyAggregateExecutionContext $context): void
        {
            (new LegacyAggregateOrderingStage())->apply($context);
        }

        /**
         * @param LegacyAggregateExecutionContext $context
         * @return array<int, array<string, mixed>>
         * @throws \Doctrine\DBAL\Exception
         * @throws Exception
         */
        protected function finalizeLegacyAggregateRows(LegacyAggregateExecutionContext $context): array
        {
            return (new LegacyAggregateFinalizeStage())->apply(
                context: $context,
                extractSnapshotAggregateMeta: function (array &$results, ?string $startDate, ?string $endDate): void {
                    $this->extractSnapshotAggregateMeta($results, $startDate, $endDate);
                },
                fillTemporalGaps: function (
                    array  $results,
                    string $temporalField,
                    string $temporalType,
                    string $startDate,
                    string $endDate,
                    array  $aggregations,
                    array  $groupBy
                ): array {
                    return $this->fillTemporalGaps($results, $temporalField, $temporalType, $startDate, $endDate, $aggregations, $groupBy);
                },
            );
        }

        public function getLastAggregateMeta(): array
        {
            return $this->lastAggregateMeta;
        }

        /**
         * @param array<int, array<string, mixed>> $results
         */
        protected function extractSnapshotAggregateMeta(array &$results, ?string $startDate, ?string $endDate): void
        {
            $meta = (new SnapshotAggregateMetaExtractor())->extract(
                results: $results,
                startDate: $startDate,
                endDate: $endDate,
                snapshotFallbackMode: $this->aggregateSnapshotFallbackMode,
            );
            if ($meta !== []) {
                $this->lastAggregateMeta = array_merge($this->lastAggregateMeta, $meta);
            }
        }

        /**
         * @param array<int, string> $values
         */
        protected function toSqlStringList(array $values): string
        {
            $escaped = array_map(static function (string $value): string {
                return "'".str_replace("'", "''", strtolower(trim($value)))."'";
            }, $values);

            return implode(',', $escaped);
        }

        /**
         * @return array{operator: string, value: mixed}
         */
        protected function resolveFilterCondition(mixed $rawValue): array
        {
            return (new FilterConditionResolver())->resolve($rawValue);
        }

        /**
         * Fills gaps in a time series result set with zeroed-out records.
         * @throws Exception
         */
        protected function fillTemporalGaps(
            array  $results,
            string $temporalField,
            string $type,
            string $startDate,
            string $endDate,
            array  $aggregations,
            array  $groupBy
        ): array
        {
            return (new TemporalGapFiller())->fill(
                results: $results,
                temporalField: $temporalField,
                type: $type,
                startDate: $startDate,
                endDate: $endDate,
                aggregations: $aggregations,
                groupBy: $groupBy,
            );
        }

        /**
         * Get default metric formulas.
         */
        protected function getDefaultFormulas(string $valCol, bool $isPostgres): array
        {
            $periodCondition = $this->getMetricPeriodConditionSql($isPostgres);

            return (new MetricDefaultFormulaBuilder())->build($valCol, $isPostgres, $periodCondition);
        }

        protected function buildCompanionTimeWeightedAverageFormula(
            array  $sourceMetricNames,
            array  $totalTimeMetricNames,
            string $valCol,
            bool   $isPostgres,
            string $periodCondition
        ): string
        {
            return (new CompanionTimeWeightedAverageFormulaBuilder())->build(
                sourceMetricNames: $sourceMetricNames,
                totalTimeMetricNames: $totalTimeMetricNames,
                valCol: $valCol,
                isPostgres: $isPostgres,
                periodCondition: $periodCondition,
                isChanneledMetric: $this->isChanneledMetric,
                toSqlStringList: fn(array $values): string => $this->toSqlStringList($values),
            );
        }

        protected function getMetricPeriodConditionSql(bool $isPostgres, string $defaultPeriod = 'daily'): string
        {
            return (new MetricPeriodConditionSqlResolver())->resolve(
                requestedPeriod: $this->aggregateRequestedPeriod,
                isPostgres: $isPostgres,
                defaultPeriod: $defaultPeriod,
            );
        }

        /**
         * Maps a framework field (e.g. metadata.clicks) to a SQL expression.
         * @throws ConfigurationException
         */
        protected function mapFieldToSql(string $expr, bool $isAggregate = false): string
        {
            $field = trim($expr);
            $lowerField = strtolower($field);

            // Specialized metric formulas for ChanneledMetric and Metric to handle cross-row aggregation
            $this->isChanneledMetric = str_ends_with($this->getEntityName(), 'ChanneledMetric');
            $isMetric = str_ends_with($this->getEntityName(), 'Analytics\Metric');
            $isPostgres = Helpers::isPostgres();

            if (($this->isChanneledMetric || $isMetric) && $isAggregate) {
                $valCol = $this->isChanneledMetric ? 'm.value' : 'e.value';
                if ($this->isChanneledMetric && $this->aggregateUseSnapshotDelta) {
                    $valCol = $this->buildSnapshotDeltaValueSql($isPostgres);
                }
                $allFormulas = array_merge($this->getDefaultFormulas($valCol, $isPostgres), self::getFormulas());
                if (isset($allFormulas[$lowerField])) {
                    $formula = $allFormulas[$lowerField];
                    if (is_callable($formula)) {
                        return $formula($valCol, $isPostgres);
                    }

                    return $formula;
                }

                // Safety net: any explicit *_daily metric name should aggregate by metric name
                // instead of being interpreted as a raw SQL column.
                if (preg_match('/^[a-z0-9_]+_daily$/', $lowerField) === 1) {
                    $metricNameExpr = $isPostgres
                        ? "LOWER(mc.name) = '$lowerField'"
                        : "mc.name = '$lowerField'";
                    $periodExpr = $isPostgres
                        ? "LOWER(mc.period) = 'daily'"
                        : "mc.period = 'daily'";

                    return "SUM(CASE WHEN $metricNameExpr AND $periodExpr THEN $valCol ELSE 0 END)";
                }

                // Dynamic catch-all for unknown simple metric names to prevent SQL syntax errors during fallback
                if (preg_match('/^[a-z0-9_]+$/', $lowerField) === 1 && !in_array($lowerField, ['id', 'value', 'period', 'name', 'metric_config_id', 'metric_date', 'platform_created_at', 'created_at', 'date'], true)) {
                    $metricNameExpr = $isPostgres
                        ? "LOWER(mc.name) IN ('$lowerField', '{$lowerField}_daily')"
                        : "mc.name IN ('$lowerField', '{$lowerField}_daily')";
                    $periodExpr = $this->getMetricPeriodConditionSql($isPostgres);

                    return "SUM(CASE WHEN $metricNameExpr AND $periodExpr THEN $valCol ELSE 0 END)";
                }

                // Prevent direct 'value' aggregation for ChanneledMetric to avoid data corruption (summing different units)
                if (str_ends_with($this->getEntityName(), 'ChanneledMetric') && ($lowerField === 'value' || str_contains($lowerField, 'm.value'))) {
                    throw new InvalidArgumentException(
                        "Direct aggregation of 'value' field is restricted for ChanneledMetrics to prevent data corruption. ".
                        "Please use intelligent formulas (e.g., 'spend', 'clicks', 'ctr', 'cpc', 'cpm', 'frequency', 'position') ".
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
                    return $this->mapFieldToSql($matches[0]);
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

                return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($source, '$.$path')) AS CHAR), 'N/A')";
            }

            // Relation metadata extraction (relationName.metadata.field)
            if (preg_match('/^([a-zA-Z0-9]+)\.(metadata|data)\.([a-zA-Z0-9_]+)$/', $field, $matches)) {
                $relName = $matches[1];
                $jsonField = $matches[2];
                $path = $matches[3];

                if (isset(self::getRelationMap()[$relName])) {
                    $map = self::getRelationMap()[$relName];
                    $source = $map['alias'].'.'.$jsonField;

                    $isPostgres = Helpers::isPostgres();
                    if ($isPostgres) {
                        return "($source #>> '{$path}')";
                    } else {
                        return "JSON_UNQUOTE(JSON_EXTRACT($source, '$.$path'))";
                    }
                }
            }

            // Handle generic Relation extraction from relationMap (JSON or standard fields)
            $normalizedField = isset(self::getRelationMap()[$field]) ? $field : (isset(self::getRelationMap()[$lowerField]) ? $lowerField : null);
            if ($normalizedField) {
                $map = self::getRelationMap()[$normalizedField];
                if (isset($map['isJSON']) && $map['isJSON']) {
                    $jsonPath = $map['jsonPath'] ?? '';
                    if (Helpers::isPostgres()) {
                        $postgresPath = '{'.str_replace('.', ',', $jsonPath).'}';

                        return "COALESCE(({$map['alias']}.{$map['field']} #>> '$postgresPath'), 'N/A')";
                    } else {
                        return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT({$map['alias']}.{$map['field']}, '$.$jsonPath')) AS CHAR), 'N/A')";
                    }
                }
                $targetCol = $map['field'] ?? 'name';

                return "{$map['alias']}.$targetCol";
            }

            // Relation mapping for metrics
            if (str_starts_with($field, 'metric.')) {
                return "m.".substr($field, 7);
            }
            if (str_starts_with($field, 'metricConfig.')) {
                $subField = substr($field, 13);

                return "mc.".$subField;
            }

            // Common aliasing for metricDate and name
            if ($field === 'metricDate') {
                if ($this->isChanneledMetric) return "m.metric_date";
                if ($isMetric) return "e.metric_date";

                return "e.metric_date"; // Fallback to root entity alias
            }
            if ($field === 'name' || $field === 'period' || $field === 'channel') {
                return "mc.$field";
            }

            // Temporal virtual fields
            $baseDate = (new DateSqlFieldResolver())->resolveBaseDateSql(
                isChanneledMetric: $this->isChanneledMetric,
                isMetric: $isMetric,
                hasEntityField: fn(string $field): bool => $this->_class->hasField($field),
            );

            $isPostgres = Helpers::isPostgres();
            $temporalDatePartSql = (new TemporalDatePartSqlResolver())->resolve($lowerField, $baseDate, $isPostgres);
            if ($temporalDatePartSql !== null) {
                return $temporalDatePartSql;
            }

            if ($field === 'value') {
                return str_ends_with($this->getEntityName(), 'Metric') ? (str_ends_with($this->getEntityName(), 'ChanneledMetric') ? 'm.value' : 'e.value') : "e.$field";
            }

            // Default: translate camelCase properties to snake_case columns
            if ($this->_class->hasField($field)) {
                return "e.".$this->_class->getColumnName($field);
            }

            return "e.$field";
        }

        protected function buildSnapshotDeltaValueSql(bool $isPostgres): string
        {
            $nullSafeComparator = $isPostgres ? 'IS NOT DISTINCT FROM' : '<=>';

            $snapshotContextSql = "
                FROM metrics m_sd
                JOIN metric_configs mc_sd ON m_sd.metric_config_id = mc_sd.id
                WHERE mc_sd.channel = mc.channel
                  AND mc_sd.period = mc.period
                  AND (mc_sd.channeled_account_id $nullSafeComparator mc.channeled_account_id)
                  AND (mc_sd.page_id $nullSafeComparator mc.page_id)
                  AND (mc_sd.post_id $nullSafeComparator mc.post_id)
                  AND (mc_sd.dimension_set_id $nullSafeComparator mc.dimension_set_id)
                  AND (mc_sd.query_id $nullSafeComparator mc.query_id)
                  AND (mc_sd.country_id $nullSafeComparator mc.country_id)
                  AND (mc_sd.device_id $nullSafeComparator mc.device_id)
            ";

            $endSnapshotSql = "SELECT MAX(m_sd.metric_date) $snapshotContextSql AND m_sd.metric_date <= :snapshotDeltaEndDate";
            if ($this->aggregateSnapshotFallbackMode === 'resilient') {
                $endSnapshotSql = "COALESCE(($endSnapshotSql), (SELECT MAX(m_sd.metric_date) $snapshotContextSql))";
            }
            $startSnapshotSql = "SELECT MAX(m_sd.metric_date) $snapshotContextSql AND m_sd.metric_date < :snapshotDeltaStartDate";

            return "(CASE
                WHEN m.metric_date = ($endSnapshotSql) THEN m.value
                WHEN m.metric_date = ($startSnapshotSql) THEN -m.value
                ELSE 0
            END)";
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
            array   $aggregations,
            array   $groupBy = [],
            ?object $filters = null,
            ?string $startDate = null,
            ?string $endDate = null
        ): QueryBuilder
        {
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
                    $query->andWhere('e.'.$key.' = :'.$key)
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

                    if ((array)$data) {
                        foreach ((array)$data as $key => $value) {
                            if (method_exists($entity, 'add'.Helpers::toCamelcase($key))) {
                                $entity->{'add'.Helpers::toCamelcase($key, true)}($value);
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
            int     $id,
            ?object $filters = null,
            ?string $startDate = null,
            ?string $endDate = null
        ): QueryBuilder
        {
            $query = $this->createBaseQueryBuilder()
                ->where('e.id = :id')
                ->setParameter('id', $id);

            if ($filters) {
                foreach ($filters as $key => $value) {
                    $query->andWhere('e.'.$key.' = :'.$key)
                        ->setParameter($key, $value);
                }
            }

            $this->applyDateFilters($query, $startDate, $endDate);

            return $query;
        }

        /**
         * @return int
         * @throws NonUniqueResultException
         * @throws NoResultException|Exception
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
         * @throws NonUniqueResultException|Exception
         */
        public function countElements(
            ?object $filters = null,
            ?string $startDate = null,
            ?string $endDate = null
        ): int
        {
            $query = $this->createBaseQueryBuilder(QueryBuilderType::COUNT);
            if ($filters) {
                foreach ($filters as $key => $value) {
                    $query->andWhere('e.'.$key.' = :'.$key)
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
         * @param array|null $extra
         * @return ArrayCollection
         * @throws Exception
         */
        public function readMultiple(
            int     $limit = 100,
            int     $pagination = 0,
            ?array  $ids = null,
            ?object $filters = null,
            string  $orderBy = 'id',
            string  $orderDir = 'DESC',
            ?string $startDate = null,
            ?string $endDate = null,
            ?array  $extra = null
        ): ArrayCollection
        {
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
                fn($item) => $this->processResult($item),
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
         * @param array|null $extra
         * @return QueryBuilder
         * @throws Exception
         */
        protected function buildReadMultipleQuery(
            ?array  $ids,
            ?object $filters,
            string  $orderBy,
            string  $orderDir,
            int     $limit,
            int     $pagination,
            ?string $startDate = null,
            ?string $endDate = null,
            ?array  $extra = null
        ): QueryBuilder
        {
            $query = $this->createBaseQueryBuilder();

            if ($ids) {
                $query->where('e.id IN (:ids)')
                    ->setParameter('ids', $ids);
            }

            if ($filters) {
                foreach ($filters as $key => $value) {
                    $query->andWhere('e.'.$key.' = :'.$key)
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
         * @throws OptimisticLockException
         * @throws ORMException
         */
        public function update(int $id, ?object $data = null, bool $returnEntity = false): bool|array|null|Entity
        {
            $entity = $this->_em->find($this->getEntityName(), $id);

            if (!$entity) {
                return false;
            }

            if ((array)$data) {
                foreach ((array)$data as $key => $value) {
                    if (method_exists($entity, 'add'.Helpers::toCamelcase($key, true))) {
                        $entity->{'add'.Helpers::toCamelcase($key, true)}($value);
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
         * @throws ORMException
         * @throws OptimisticLockException
         */
        public function delete(int $id): bool
        {
            $entity = $this->_em->find($this->getEntityName(), $id);

            if (!$entity) {
                return false;
            }

            $props = $this->_class->fieldMappings;

            foreach ($props as $key => $value) {
                if (is_a($entity->{'get'.Helpers::toCamelcase($key, true)}(), 'Collection')) {
                    $entity->{'remove'.Helpers::toCamelcase($key, true)}($entity->{'get'.Helpers::toCamelcase($key, true)}());
                }
            }

            $this->_em->remove($entity);
            $this->_em->flush();

            return true;
        }
    }

