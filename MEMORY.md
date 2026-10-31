# APIs Hub Memory
## Scope
- Package role: Orchestration (Worker)
- Purpose: This package operates within the Orchestration (Worker) layer of the APIs Hub SaaS hierarchy, coordinating caching, normalization, persistence, and data aggregation across source integrations.
- Dependency stance: Consumes `anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, `anibalealvarezs/facebook-graph-api`, `anibalealvarezs/google-api`, `anibalealvarezs/google-hub-driver`, `anibalealvarezs/meta-hub-driver`, `anibalealvarezs/klaviyo-hub-driver`, `anibalealvarezs/shopify-hub-driver`, `anibalealvarezs/netsuite-hub-driver`, `anibalealvarezs/amazon-hub-driver`, `anibalealvarezs/bigcommerce-hub-driver`, `anibalealvarezs/pinterest-hub-driver`, `anibalealvarezs/linkedin-hub-driver`, `anibalealvarezs/tiktok-hub-driver`, `anibalealvarezs/x-hub-driver`, and `anibalealvarezs/triple-whale-hub-driver`; serves the APIs Hub Facade and cached-data consumers.
## Local working rules
- Sync queue telemetry (2026-10-29): `delayed` remains pending work, used by `ProcessJobsCommand` for unmet dependencies and rate limits, and is claimable alongside `scheduled`. Both telemetry scopes must include delayed jobs in the public `scheduled` counter and historical completion denominator; recurring recent jobs retain their pending-work exclusion from completion. `Infrastructure/ScaleWorkersCommand` must include delayed jobs in demand so a delayed-only queue cannot scale to zero. The separate `Analytics/ScaleDownCommand` fix in `56b6263` did not cover this pool scaler. Global telemetry must explicitly pass its 30-second TTL; `CacheService::get()` otherwise defaults to one hour. Regression coverage: `SyncTelemetryServiceTest` and `ScaleWorkersCommandTest` (isolated, no Docker mutations).
- Consult `AGENTS.md` first for package-specific instructions.
- Use this `MEMORY.md` for repository-specific decisions, learnings, and follow-up notes.
- Use `D:\laragon\www\_shared\AGENTS.md` and `D:\laragon\www\_shared\MEMORY.md` for cross-repository protocols and workspace-wide learnings.
- Keep secrets, credentials, tokens, and private endpoints out of this file.
## Current notes
- GA4 expansion planning (2026-10-30): docs/google-analytics-expansion-plan.md extends existing purpose-driven scopes; nine requested dimensions is a hard Core report budget (date counts if requested). Joint breakdown/filter support and metric additivity must be declared; never join marginal reports to invent a higher-dimensional cube or sum overlapping user/session/event scopes. First tranche fixes ordinary/custom event discovery, then property-specific capabilities/recipes. Every increment updates the GA4 catalog and consumers together. Single-day constant-date omission is a proposed optimization requiring validation; no expansion code or production replay is implied by the plan.
- Release v1.18.4 (2026-10-30): adds 1.18.3 -> 1.18.4 capability validation with no schema/identity rewrite or automatic replay. Earlier tenants retain the intervening GSC migration. Pins Google driver 1.3.1, core 1.1.4, Google SDK 1.1.1, Meta driver 1.2.1 and Mailchimp driver 1.1.1; Facebook SDK stays 1.2.0. See docs/releases/v1.18.4.md and GA4 rollout requirements. Facade registration is separate from tenant upgrades.
- Driver query contracts (2026-10-30): optional profile query_contract is preserved across older core normalizers, validated before path selection and executed through contract-aware universal SQL without legacy fallback. GA4 pilot declares matrix availability, unsupported cost, same-grain companion-weighted rates (NULL on incomplete/duplicate/negative weights), non-unique reported-user quality, virtual dimensions and currency guards. No provider names are hardcoded in policy/SQL helpers. Calendar grains only; rolling/snapshot requests rejected. Empty canonical dictionary entries explicitly prohibit generic fallback. See docs/ga4-correctness-rollout.md; PostgreSQL scale/source acceptance and production release remain pending. No historical signature migration is needed for default GA4 identities.
- Upgrade verification (2026-10-29): HealthService resolves MCP via service DNS `mcp:3000` inside containers, retaining the host-published port outside Docker, and normalizes heartbeat URLs without duplicating `/api/heartbeat`. Failed heartbeat reporting makes the diagnostic command fail. setup-cron debug output must redact ENV values. PostgreSQL GSC integration coverage uses an explicit GSC_UPGRADE_TEST_DSN and random disposable schemas; GSC_UPGRADE_TEST_CONFIGS=612702 opts into the observed volume/duplicate ratio. No PostgreSQL runtime is available locally; SQLite passing is not PostgreSQL validation. See docs/gsc-upgrade-postgres-validation.md.
- GSC 1.18.3 backfill correction: production-sized datasets can contain hundreds of thousands of configs; never perform multiple metric-table updates per config. Cache transformations per old dimension set, stage canonical signatures in batches, reconcile collisions with shared MetricConfigDeduplicator, analyze the staging table, and bulk-update each of the three affected tables once. Emit staging and write-phase progress. Reuse `MetricConfigSignature`, also consumed by the established recalculation command. Keep the transaction atomic and preserve cached values.
- GSC type upgrade 1.18.2 → 1.18.3 adds `search_type=web` only to untyped GSC configuration dimension sets, recomputes canonical config signatures using shared `Services\Analytics\MetricConfigSignature`, and updates metric hashes/channeled dimension links in one transaction. Other channels and non-colliding typed records are untouched. Workers must be stopped; PostgreSQL metric-table locks prevent concurrent old-identity writes. Collisions use the established recalculation policy: retain the oldest config ID and overlapping values from the newer config, move unique dates and preserve channel links; never sum duplicate observations. Historical additional-type ingestion requires a GSC-only replay without wiping metrics; no global nuclear resync. SQLite integration tests exercise real DML, rollback and idempotence, but skip PostgreSQL table locks.
- Internal API resource guard: `RequestBudgetPolicy`/`RequestBudgetLimiter` add atomic Redis tenant+actor work-token budgets and concurrency leases after authentication, with no admin/IP exemption. Data aggregations, KPI orchestration, and management have independent pools; health paths remain exempt. Capacity denial returns 429/Retry-After, Redis outage returns 503, and leases release in finally or expire after crashes. Trusted facade HMAC actor headers require admin auth. Deployment defaults, lease-timeout requirements, diagnostics, and isolated Redis tests are in `docs/internal-api-resource-policy.md`.
- Entity attribute aggregation filters (2026-10-29): `attributes.<MetricConfig owning to-one relationship>.<mapped scalar property>` is resolved by `EntityAttributeResolver` using Doctrine metadata, with nested singular paths and no channel/attribute whitelist. `EntityAttributeFilter` shares typed predicates across universal and legacy execution. Attribute requests bypass specialized strategies that do not apply these predicates; snapshots retain legacy snapshot semantics. `POST /{channel}/metric/attributes` discovers direct scalar fields/enums and account/channel-scoped facet values (200-value cap). Current synced attributes, stored numeric units, scalar-only contract; see `docs/entity-attribute-filters.md`.
- MCP server dependency management now uses `pnpm` with `mcp-server/.npmrc` enforcing `minimum-release-age=1440` and `block-exotic-subdeps=true`; Docker builds activate pnpm via Corepack before installing MCP dependencies.
- Orchestrator owns caching, normalization, persistence, and aggregation.
- Prefer Doctrine-managed schema changes for index work; avoid direct manual `CREATE INDEX` unless there is explicit emergency authorization.
- Use the metric aggregation strategy abstraction and the metric profile index planner to derive candidate indexes from driver-defined profiles before materializing them in Doctrine metadata.
- Keep weighted aggregate logic behavior-driven and reusable; avoid hardcoding channel/metric names when a strategy or template can express the same rule.
- Cache strategy must tolerate empty-but-successful responses and normalize cache keys recursively so repeated heavy misses do not re-run the same aggregate workload.
- In aggregate filtering, relation keys flagged as `isAttribute` (e.g. `page_platform_id`, JSON-backed linked ids) must be filtered by their mapped attribute SQL expression, not by FK identity (`mc.<fk>`).
- Phase 1 of the aggregation refactor now routes `BaseRepository::aggregate()` through `Services\Aggregation\AggregationPlanner` and `Services\Aggregation\AggregationExecutor`, while keeping the existing legacy SQL path as a temporary fallback with explicit aggregate metadata (`planned_execution_path`, `execution_path`, `fallback_reason`).
- Phase 1 planner hardening now classifies fallback reasons semantically before execution (`unsupported_entity_type`, `unsupported_filter_operator`, `missing_reducer_strategy`, `unsupported_group_pattern`) and exposes that analysis via planner stages for telemetry-driven follow-up.
- Phase 1 planner now also narrows `candidate_optimized_strategies` using the current optimized-path semantics (FB Organic page summary, linked pages, post snapshot, marketing hierarchy, weighted metric) instead of always probing every optimized branch.
- Phase 1 legacy extraction has started inside `BaseRepository`: scope setup, aggregate select building, and result finalization/temporal smoothing are now separated into reusable helpers so the executor can progressively evolve from router to staged pipeline without a big-bang rewrite.
- Phase 1 legacy extraction now also separates relation/grouping handling, filter application, date/snapshot constraints, and ordering into dedicated helpers, leaving the remaining inline logic concentrated in the SQL semantics rather than in one giant execution method.
- Phase 1 legacy execution is now explicitly plan-driven in key paths: `BaseRepository::executeLegacyAggregationPlan()` reads `AggregationPlan` context/stages for entity/engine flags, grouping, ordering, weighted reducer hints, and temporal post-processing decisions instead of recomputing them ad hoc.
- Phase 1 now introduces `Services\Aggregation\LegacyAggregateExecutionContext` as the structured state carrier for legacy execution (qb, plan, grouping/filter/date/order, engine/entity flags, relation context), reducing parameter sprawl and preparing executor-side staged orchestration.
- Phase 1 now externalizes legacy SQL semantics for grouping and filtering into dedicated stage services (`Services\Aggregation\Stages\LegacyAggregateGroupingStage`, `Services\Aggregation\Stages\LegacyAggregateFilterStage`) with `BaseRepository` delegating via callbacks, reducing repository-level SQL branching density while preserving behavior.
- Phase 1 now also externalizes legacy date/snapshot constraint semantics into `Services\Aggregation\Stages\LegacyAggregateDateStage`, including latest-snapshot handling, snapshot-delta guardrails, and date-range filters, further reducing orchestration logic left inside `BaseRepository`.
- Phase 1 now also externalizes legacy ordering and result finalization into `Services\Aggregation\Stages\LegacyAggregateOrderingStage` and `Services\Aggregation\Stages\LegacyAggregateFinalizeStage`, with `BaseRepository` delegating post-query ordering and temporal-gap smoothing via callbacks.
- Phase 1 now also externalizes legacy relation-context assembly (standard relations/date fields/root alias/join callbacks) into `Services\Aggregation\Stages\LegacyAggregateRelationContextStage`, reducing closure-heavy setup logic in `BaseRepository`.
- Phase 1 now also externalizes legacy aggregate select construction and weighted-reducer precheck into `Services\Aggregation\Stages\LegacyAggregateSelectStage`, with `BaseRepository` keeping only stage delegation and mutable join-state assignment.
- Phase 1 now also externalizes legacy scope-join bootstrapping (`metrics`/`metric_configs`) into `Services\Aggregation\Stages\LegacyAggregateScopeStage`, further reducing execution-path-specific join wiring inside `BaseRepository`.
- Phase 1 is now considered closed: `BaseRepository::executeLegacyAggregationPlan()` delegates to a dedicated stage-driven pipeline method (`runLegacyAggregatePipeline`) that orchestrates scope, select, relation-context, grouping, filters, date constraints, ordering, and finalization as explicit stages.
- Phase 2 local cleanup has started by extracting temporal smoothing into `Services\Aggregation\TemporalGapFiller`, with `BaseRepository::fillTemporalGaps()` now delegating to that service to reduce repository utility density while preserving behavior.
- Phase 2 local cleanup now also extracts snapshot metadata post-processing into `Services\Aggregation\SnapshotAggregateMetaExtractor`, with `BaseRepository::extractSnapshotAggregateMeta()` delegating and merging returned metadata.
- Phase 2 local cleanup now also extracts filter-operator normalization into `Services\Aggregation\FilterConditionResolver`, with `BaseRepository::resolveFilterCondition()` delegating to preserve semantics while reducing inline parsing utilities.
- Phase 2 local cleanup now also extracts base date SQL fallback selection into `Services\Aggregation\DateSqlFieldResolver`, with `BaseRepository::mapFieldToSql()` delegating temporal base-date resolution.
- Phase 2 local cleanup now also extracts temporal date-part alias SQL mapping into `Services\Aggregation\TemporalDatePartSqlResolver`, reducing branching inside `BaseRepository::mapFieldToSql()` while preserving PostgreSQL/MySQL semantics.
- Phase 2 local cleanup now also extracts default metric formula construction into `Services\Aggregation\MetricDefaultFormulaBuilder`, with `BaseRepository::getDefaultFormulas()` delegating while preserving period-aware override behavior.
- Phase 2 local cleanup now also extracts metric period SQL condition generation into `Services\Aggregation\MetricPeriodConditionSqlResolver`, with `BaseRepository::getMetricPeriodConditionSql()` delegating requested-period normalization and SQL rendering.
- Phase 2 local cleanup now also extracts companion weighted-average SQL assembly into `Services\Aggregation\CompanionTimeWeightedAverageFormulaBuilder`, with `BaseRepository::buildCompanionTimeWeightedAverageFormula()` delegating comparator/date-column/list assembly behavior.
- Fallback telemetry hardening has started in `Services\Aggregation\AggregationExecutor`: execution results now include executor-level decision metadata (`executor_path_decision`, `optimized_attempted`, `optimized_candidate_count`, `executor_fallback_reason`, `executor_fallback_reason_source`) to distinguish planner-provided vs executor-derived fallback causes.
- Fallback telemetry now also propagates planner-stage diagnostics into execution metadata under `planner_diagnostics` (fallback reason, unsupported operators, missing reducer expressions, and normalized group pattern when present).
- `BaseRepository::aggregate()` now merges executor result metadata back into `lastAggregateMeta`, so `getLastAggregateMeta()` consumers receive planner/executor diagnostics consistently.
- Controller contract coverage now includes `ChanneledCrudController::aggregate()` metadata passthrough validation (`execution_path`, `fallback_reason`, cache flags) via a real-method unit test helper, reducing risk that repository telemetry is dropped at the HTTP response layer.
- Aggregate controller contract coverage now also validates passthrough of dynamic repository metadata keys (no rigid whitelist) in `ChanneledCrudController::aggregate()`, while preserving base cache metadata fields in the HTTP response.
- Shared Phase 2 integration has started from the planner side: `Services\Aggregation\AggregationPlanner` now supports optional channel-profile capability validation (via driver-provided aggregation profiles) and emits `missing_profile_capability` when profiles are declared but no profile supports the requested aggregation shape.
- Planner-side profile capability validation is now pilot-validated against a real registered driver (`google_search_console`), confirming profile loading via driver registry and stage metadata reporting (`profiles.checked/supported/matched_profiles`).
- Planner-side profile capability validation now covers two real pilot channels (`google_search_console`, `facebook_organic`), reducing uncertainty before broader Shared Phase 2 rollout.
- Planner profile capability validation now also resolves numeric `filters.channel` IDs to channel keys (via injectable resolver or `Channel::tryFrom()` compatibility bridge), allowing profile checks in id-based request flows without requiring string channel filters.
- Profile-loading helpers were extracted from `AggregationPlanner` into `Services\Aggregation\AggregationProfileResolver`, centralizing driver-registry lookup + normalization and reducing planner orchestration density.
- Executor planner diagnostics now include profile-stage telemetry (`profile_checked`, `profile_supported`, `profile_channel`, `profile_count`, `profile_failure_reason`) so fallback-rate comparisons can be segmented across pilot and non-pilot channels.
- Added `Services\Aggregation\AggregationFallbackTelemetryReporter` to summarize `missing_profile_capability` deltas by pilot/non-pilot segmentation and per-channel buckets from executor metadata events.
- Added CLI command `app:aggregation-telemetry-report` to turn captured executor metadata JSON payloads into periodic pilot/non-pilot fallback summaries, with optional output-file snapshots for operational use.
- Added `Services\Aggregation\AggregationTelemetryEventRecorder` and wired it into `BaseRepository::aggregate()` as an opt-in JSONL sink (`AGGREGATION_TELEMETRY_PATH`), giving the reporting command a concrete append-only event source.
- `AggregationExecutor` now owns aggregate request orchestration dependencies (planner + optional telemetry recorder) through `executeAggregate()`, reducing `BaseRepository::aggregate()` to context initialization, delegation, and final metadata mirroring.
- Pilot expansion for Phase 2 aggregation profiles has started for `facebook_marketing`, `klaviyo`, and `shopify`:
    - `FacebookMarketingDriver`, `KlaviyoDriver`, and `ShopifyDriver` now implement `AggregationProfileProviderInterface`.
    - New templates `flowCampaignProfile` and `storeProfile` were added to `api-driver-core` to support these channels.
    - This enables strict planner-side validation for ads, email automation, and e-commerce aggregation queries.

- Phase 5 (Legacy Retirement) & Cleanup:
    - Initiated extraction of optimized aggregation strategies from `BaseRepository` into dedicated service classes.
    - New strategies: `WeightedMetricStrategy`, `FacebookOrganicStrategy`, `MarketingHierarchyStrategy`.
    - Introduced `OptimizedAggregationStrategyInterface` and `OptimizedAggregationHelpersTrait` for consistent SQL generation.
    - Centralized grouping and dimension resolution in `AggregationGroupingResolver`.
    - `BaseRepository::executeOptimizedAggregationPlan()` refactored to delegate to strategy classes, significantly reducing repository complexity.
    - The repository now acts as a context provider and proxy for legacy helpers during the progressive retirement of optimized paths.
- New cross-repo track opened for strategy metric semantics: canonical metric equivalence will be resolved in read-only mode at aggregation time (pre-query), keeping raw provider metric names in persisted data; reference plan: `D:\laragon\www\_shared\data\aggregation-metric-equivalence-readonly-plan-2026-05-05.md`.
- Shared Phase A artifacts are now available in `api-driver-core` for this track (`CanonicalMetricDefinitionRegistry` + `CanonicalMetricDictionaryProviderInterface`), enabling Phase B integration of a canonical metric SQL resolver in `apis-hub`.
- During Phase B work, an unexpected test import regression was detected and corrected in `tests/Unit/Services/Aggregation/AggregationPlannerTest.php` (`Traits\AggregationPlanner` -> `Services\Aggregation\AggregationPlanner`), restoring planner regression coverage.
- A second unexpected test import regression was detected and corrected in `tests/Unit/Services/Aggregation/AggregationExecutorTest.php` (`Traits\*` imports -> `Services\Aggregation\*`), restoring executor regression coverage.
- Phase B now routes `MarketingHierarchyStrategy` metric expressions through `CanonicalMetricSqlResolver` using read-only precedence `override -> driver dictionary -> default`, preserving raw provider metric names in SQL predicates.
- `MarketingHierarchyStrategy` now appends `metric_resolution` metadata (`channel`, `strategy`, resolved canonical/raw metrics, source) into aggregate meta via repository strategy hook for telemetry/diagnostics.
- When canonical resolution fails in `MarketingHierarchyStrategy`, strategy metadata now sets `strategy_fallback_reason=missing_metric_equivalence`; `AggregationExecutor` consumes it as explicit legacy fallback reason with `executor_fallback_reason_source=strategy` when planner does not provide a fallback reason.
- Canonical dictionary pilot is now cross-channel: `CanonicalMetricSqlResolver` test coverage includes Meta and Shopify registry-provider resolution paths, reducing risk that the resolver is coupled to one driver family.
- Canonical dictionary pilot now covers a third channel (`klaviyo`), and resolver registry-path tests validate multi-channel behavior across Meta, Shopify, and Klaviyo providers.
- Google priority pilot is now complete (`google_search_console` dictionary-provider path validated), and pilot expansion is paused as agreed; next work should focus on remaining implementation-plan tasks rather than adding more pilot channels.
- Stable integration path is now formalized: overrides live in `config/aggregation/metric_equivalences.yaml`, operator/docs are in `docs/aggregation-metric-equivalence.md`, and executor telemetry persistence is covered for `metric_resolution` metadata.
- Ambiguous legacy marketing inputs are now explicitly classified during canonical resolution: stable aliases (for example `results -> conversions`) emit `input_type=legacy_alias`, while `actions` remains temporarily backward-compatible but is flagged via `metric_resolution.deprecation` as `ambiguous_metric_alias` instead of being treated as a canonical metric.

### 2026-05-05 - Session 2 & 3: Agnostic Aggregation Engine Finalization
- **Decisions**:
  - Eliminated `BaseRepository::getChannelKey()` to ensure repositories don't hold channel-specific knowledge.
  - Migrated `AggregationPlanner` to a fully agnostic matching model using account type suffixes (`_page`, `_account`) and `default_filters` from profiles.
  - Decoupled platform identity fields (e.g., `facebook_page_id`) by resolving them dynamically through `DriverFactory` and the `getPlatformEntityIdField()` driver contract.
- **Applied Changes**:
  - `AggregationPlanner.php`: Replaced hardcoded channel checks with profile-driven defaults and generic type matching.
  - `AggregationEntityFieldResolver.php`: Switched from `match($channel)` to dynamic driver lookup via `getPlatformEntityIdField()`.
  - `CanonicalMetricSqlResolver.php`: Removed hardcoded channel dictionaries; now relies on driver-provided mappings.
  - `BaseRepository.php`: Removed legacy `getChannelKey()` fallback and integrated agnostic identity resolution.
- **Next Steps**:
  - Clean up the `Strategies` folder by deleting the now-obsolete `FacebookOrganicStrategy.php`.
  ### 2026-05-05 - Session 4: Agnostic Discovery & Optimized Activation
- **Decision**: Implemented agnostic channel discovery in `AggregationPlanner` to resolve optimized paths for generic metric requests.
- **Bug**: The optimized path for Instagram account summaries (`facebook_organic_linked_pages_flow`) was failing to activate because the planner could not infer the channel without hardcoded logic.
- **Changes**:
    - `AggregationProfileResolver`: Added `resolveAll()` to allow the planner to search for matching profiles across all registered drivers.
    - `AggregationPlanner`:
        - Updated `evaluateProfileCapability` to discover the channel agnostically if not provided.
        - Relaxed `isMetric` entity restrictions to allow optimized strategies for the global metrics table.
        - Added the discovered channel to the `AggregationPlan` context.
    - `SocialOrganicStrategy` & `MarketingHierarchyStrategy`: Updated to retrieve the channel key from the plan context, ensuring correct SQL generation even without explicit channel filters.
- **Next Steps**: Validate the fix with the specific Facebook Organic payload reported by the user.

### 2026-05-05 - Orchestrator Modernization Phase 0
- **Problem**: Dedicated channel containers create deployment overhead and prevent granular account syncs without full restarts. Stuck jobs require manual cleanup.
- **Solution**: Move to an agnostic worker pool with dynamic scaling via Docker socket and atomic job resumption.
- **Actions**: Created implementation plan and handoff docs in `_shared`. Completed backend implementation for agnostic workers, auto-scaling, and granular sync.
- **Next Steps**: Implement Phase 5 (Monitoring UI Update) and expand granular sync support to other drivers.

### 2026-05-06 - Deployment Stability and OOM Resolution
- **Issue**: Containers (master, mcp, worker) were crashing with Code 137 (OOM) during Google Search Console config updates.
- **Cause**: `entrypoint.sh` was running `composer update` on every master start/restart, causing massive memory pressure. Also, the scalable worker pool lacked resource limits.
- **Fixes**:
    - Removed `composer update` from `entrypoint.sh`. Startup is now lean.
    - Added CPU/Memory limits to `master` (512MB), `worker` (384MB), and `mcp` (256MB) in `bin/build-deployment.php`.
    - Fixed MCP service discovery to use the `master` network name instead of `127.0.0.1`.
    - Added `--no-recreate` to `ScaleWorkersCommand` to prevent accidental service interruptions during scaling.
- **Protocol**: Dependencies must be managed during the build phase (Dockerfile) or manually, never during container startup.



### 2026-05-07 - Sync Telemetry Engine Implementation
- **Decision**: Implemented a Redis-cached telemetry API to track sync progress across all channels and assets.
- **Components**:
    - `SyncTelemetryService`: Calculates completion % using `JSON_EXTRACT` (MySQL) or JSONB operators (PostgreSQL).
    - `SyncStatusController`: Exposes `GET /api/sync/status` with API Key security.
# APIs Hub Memory
## Scope
- Package role: Orchestration (Worker)
- Purpose: This package operates within the Orchestration (Worker) layer of the APIs Hub SaaS hierarchy, coordinating caching, normalization, persistence, and data aggregation across source integrations.
- Dependency stance: Consumes `anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, `anibalealvarezs/facebook-graph-api`, `anibalealvarezs/google-api`, `anibalealvarezs/google-hub-driver`, `anibalealvarezs/meta-hub-driver`, `anibalealvarezs/klaviyo-hub-driver`, `anibalealvarezs/shopify-hub-driver`, `anibalealvarezs/netsuite-hub-driver`, `anibalealvarezs/amazon-hub-driver`, `anibalealvarezs/bigcommerce-hub-driver`, `anibalealvarezs/pinterest-hub-driver`, `anibalealvarezs/linkedin-hub-driver`, `anibalealvarezs/tiktok-hub-driver`, `anibalealvarezs/x-hub-driver`, and `anibalealvarezs/triple-whale-hub-driver`; serves the APIs Hub Facade and cached-data consumers.
## Local working rules
- Consult `AGENTS.md` first for package-specific instructions.
- Use this `MEMORY.md` for repository-specific decisions, learnings, and follow-up notes.
- Use `D:\laragon\www\_shared\AGENTS.md` and `D:\laragon\www\_shared\MEMORY.md` for cross-repository protocols and workspace-wide learnings.
- Keep secrets, credentials, tokens, and private endpoints out of this file.
## Current notes
- MCP server dependency management now uses `pnpm` with `mcp-server/.npmrc` enforcing `minimum-release-age=1440` and `block-exotic-subdeps=true`; Docker builds activate pnpm via Corepack before installing MCP dependencies.
- Orchestrator owns caching, normalization, persistence, and aggregation.
- Prefer Doctrine-managed schema changes for index work; avoid direct manual `CREATE INDEX` unless there is explicit emergency authorization.
- Use the metric aggregation strategy abstraction and the metric profile index planner to derive candidate indexes from driver-defined profiles before materializing them in Doctrine metadata.
- Keep weighted aggregate logic behavior-driven and reusable; avoid hardcoding channel/metric names when a strategy or template can express the same rule.
- Cache strategy must tolerate empty-but-successful responses and normalize cache keys recursively so repeated heavy misses do not re-run the same aggregate workload.
- In aggregate filtering, relation keys flagged as `isAttribute` (e.g. `page_platform_id`, JSON-backed linked ids) must be filtered by their mapped attribute SQL expression, not by FK identity (`mc.<fk>`).
- Phase 1 of the aggregation refactor now routes `BaseRepository::aggregate()` through `Services\Aggregation\AggregationPlanner` and `Services\Aggregation\AggregationExecutor`, while keeping the existing legacy SQL path as a temporary fallback with explicit aggregate metadata (`planned_execution_path`, `execution_path`, `fallback_reason`).
- Phase 1 planner hardening now classifies fallback reasons semantically before execution (`unsupported_entity_type`, `unsupported_filter_operator`, `missing_reducer_strategy`, `unsupported_group_pattern`) and exposes that analysis via planner stages for telemetry-driven follow-up.
- Phase 1 planner now also narrows `candidate_optimized_strategies` using the current optimized-path semantics (FB Organic page summary, linked pages, post snapshot, marketing hierarchy, weighted metric) instead of always probing every optimized branch.
- Phase 1 legacy extraction has started inside `BaseRepository`: scope setup, aggregate select building, and result finalization/temporal smoothing are now separated into reusable helpers so the executor can progressively evolve from router to staged pipeline without a big-bang rewrite.
- Phase 1 legacy extraction now also separates relation/grouping handling, filter application, date/snapshot constraints, and ordering into dedicated helpers, leaving the remaining inline logic concentrated in the SQL semantics rather than in one giant execution method.
- Phase 1 legacy execution is now explicitly plan-driven in key paths: `BaseRepository::executeLegacyAggregationPlan()` reads `AggregationPlan` context/stages for entity/engine flags, grouping, ordering, weighted reducer hints, and temporal post-processing decisions instead of recomputing them ad hoc.
- Phase 1 now introduces `Services\Aggregation\LegacyAggregateExecutionContext` as the structured state carrier for legacy execution (qb, plan, grouping/filter/date/order, engine/entity flags, relation context), reducing parameter sprawl and preparing executor-side staged orchestration.
- Phase 1 now externalizes legacy SQL semantics for grouping and filtering into dedicated stage services (`Services\Aggregation\Stages\LegacyAggregateGroupingStage`, `Services\Aggregation\Stages\LegacyAggregateFilterStage`) with `BaseRepository` delegating via callbacks, reducing repository-level SQL branching density while preserving behavior.
- Phase 1 now also externalizes legacy date/snapshot constraint semantics into `Services\Aggregation\Stages\LegacyAggregateDateStage`, including latest-snapshot handling, snapshot-delta guardrails, and date-range filters, further reducing orchestration logic left inside `BaseRepository`.
- Phase 1 now also externalizes legacy ordering and result finalization into `Services\Aggregation\Stages\LegacyAggregateOrderingStage` and `Services\Aggregation\Stages\LegacyAggregateFinalizeStage`, with `BaseRepository` delegating post-query ordering and temporal-gap smoothing via callbacks.
- Phase 1 now also externalizes legacy relation-context assembly (standard relations/date fields/root alias/join callbacks) into `Services\Aggregation\Stages\LegacyAggregateRelationContextStage`, reducing closure-heavy setup logic in `BaseRepository`.
- Phase 1 now also externalizes legacy aggregate select construction and weighted-reducer precheck into `Services\Aggregation\Stages\LegacyAggregateSelectStage`, with `BaseRepository` keeping only stage delegation and mutable join-state assignment.
- Phase 1 now also externalizes legacy scope-join bootstrapping (`metrics`/`metric_configs`) into `Services\Aggregation\Stages\LegacyAggregateScopeStage`, further reducing execution-path-specific join wiring inside `BaseRepository`.
- Phase 1 is now considered closed: `BaseRepository::executeLegacyAggregationPlan()` delegates to a dedicated stage-driven pipeline method (`runLegacyAggregatePipeline`) that orchestrates scope, select, relation-context, grouping, filters, date constraints, ordering, and finalization as explicit stages.
- Phase 2 local cleanup has started by extracting temporal smoothing into `Services\Aggregation\TemporalGapFiller`, with `BaseRepository::fillTemporalGaps()` now delegating to that service to reduce repository utility density while preserving behavior.
- Phase 2 local cleanup now also extracts snapshot metadata post-processing into `Services\Aggregation\SnapshotAggregateMetaExtractor`, with `BaseRepository::extractSnapshotAggregateMeta()` delegating and merging returned metadata.
- Phase 2 local cleanup now also extracts filter-operator normalization into `Services\Aggregation\FilterConditionResolver`, with `BaseRepository::resolveFilterCondition()` delegating to preserve semantics while reducing inline parsing utilities.
- Phase 2 local cleanup now also extracts base date SQL fallback selection into `Services\Aggregation\DateSqlFieldResolver`, with `BaseRepository::mapFieldToSql()` delegating temporal base-date resolution.
- Phase 2 local cleanup now also extracts temporal date-part alias SQL mapping into `Services\Aggregation\TemporalDatePartSqlResolver`, reducing branching inside `BaseRepository::mapFieldToSql()` while preserving PostgreSQL/MySQL semantics.
- Phase 2 local cleanup now also extracts default metric formula construction into `Services\Aggregation\MetricDefaultFormulaBuilder`, with `BaseRepository::getDefaultFormulas()` delegating while preserving period-aware override behavior.
- Phase 2 local cleanup now also extracts metric period SQL condition generation into `Services\Aggregation\MetricPeriodConditionSqlResolver`, with `BaseRepository::getMetricPeriodConditionSql()` delegating requested-period normalization and SQL rendering.
- Phase 2 local cleanup now also extracts companion weighted-average SQL assembly into `Services\Aggregation\CompanionTimeWeightedAverageFormulaBuilder`, with `BaseRepository::buildCompanionTimeWeightedAverageFormula()` delegating comparator/date-column/list assembly behavior.
- Fallback telemetry hardening has started in `Services\Aggregation\AggregationExecutor`: execution results now include executor-level decision metadata (`executor_path_decision`, `optimized_attempted`, `optimized_candidate_count`, `executor_fallback_reason`, `executor_fallback_reason_source`) to distinguish planner-provided vs executor-derived fallback causes.
- Fallback telemetry now also propagates planner-stage diagnostics into execution metadata under `planner_diagnostics` (fallback reason, unsupported operators, missing reducer expressions, and normalized group pattern when present).
- `BaseRepository::aggregate()` now merges executor result metadata back into `lastAggregateMeta`, so `getLastAggregateMeta()` consumers receive planner/executor diagnostics consistently.
- Controller contract coverage now includes `ChanneledCrudController::aggregate()` metadata passthrough validation (`execution_path`, `fallback_reason`, cache flags) via a real-method unit test helper, reducing risk that repository telemetry is dropped at the HTTP response layer.
- Aggregate controller contract coverage now also validates passthrough of dynamic repository metadata keys (no rigid whitelist) in `ChanneledCrudController::aggregate()`, while preserving base cache metadata fields in the HTTP response.
- Shared Phase 2 integration has started from the planner side: `Services\Aggregation\AggregationPlanner` now supports optional channel-profile capability validation (via driver-provided aggregation profiles) and emits `missing_profile_capability` when profiles are declared but no profile supports the requested aggregation shape.
- Planner-side profile capability validation is now pilot-validated against a real registered driver (`google_search_console`), confirming profile loading via driver registry and stage metadata reporting (`profiles.checked/supported/matched_profiles`).
- Planner-side profile capability validation now covers two real pilot channels (`google_search_console`, `facebook_organic`), reducing uncertainty before broader Shared Phase 2 rollout.
- Planner profile capability validation now also resolves numeric `filters.channel` IDs to channel keys (via injectable resolver or `Channel::tryFrom()` compatibility bridge), allowing profile checks in id-based request flows without requiring string channel filters.
- Profile-loading helpers were extracted from `AggregationPlanner` into `Services\Aggregation\AggregationProfileResolver`, centralizing driver-registry lookup + normalization and reducing planner orchestration density.
- Executor planner diagnostics now include profile-stage telemetry (`profile_checked`, `profile_supported`, `profile_channel`, `profile_count`, `profile_failure_reason`) so fallback-rate comparisons can be segmented across pilot and non-pilot channels.
- Added `Services\Aggregation\AggregationFallbackTelemetryReporter` to summarize `missing_profile_capability` deltas by pilot/non-pilot segmentation and per-channel buckets from executor metadata events.
- Added CLI command `app:aggregation-telemetry-report` to turn captured executor metadata JSON payloads into periodic pilot/non-pilot fallback summaries, with optional output-file snapshots for operational use.
- Added `Services\Aggregation\AggregationTelemetryEventRecorder` and wired it into `BaseRepository::aggregate()` as an opt-in JSONL sink (`AGGREGATION_TELEMETRY_PATH`), giving the reporting command a concrete append-only event source.
- `AggregationExecutor` now owns aggregate request orchestration dependencies (planner + optional telemetry recorder) through `executeAggregate()`, reducing `BaseRepository::aggregate()` to context initialization, delegation, and final metadata mirroring.
- Pilot expansion for Phase 2 aggregation profiles has started for `facebook_marketing`, `klaviyo`, and `shopify`:
    - `FacebookMarketingDriver`, `KlaviyoDriver`, and `ShopifyDriver` now implement `AggregationProfileProviderInterface`.
    - New templates `flowCampaignProfile` and `storeProfile` were added to `api-driver-core` to support these channels.
    - This enables strict planner-side validation for ads, email automation, and e-commerce aggregation queries.

- Phase 5 (Legacy Retirement) & Cleanup:
    - Initiated extraction of optimized aggregation strategies from `BaseRepository` into dedicated service classes.
    - New strategies: `WeightedMetricStrategy`, `FacebookOrganicStrategy`, `MarketingHierarchyStrategy`.
    - Introduced `OptimizedAggregationStrategyInterface` and `OptimizedAggregationHelpersTrait` for consistent SQL generation.
    - Centralized grouping and dimension resolution in `AggregationGroupingResolver`.
    - `BaseRepository::executeOptimizedAggregationPlan()` refactored to delegate to strategy classes, significantly reducing repository complexity.
    - The repository now acts as a context provider and proxy for legacy helpers during the progressive retirement of optimized paths.
- New cross-repo track opened for strategy metric semantics: canonical metric equivalence will be resolved in read-only mode at aggregation time (pre-query), keeping raw provider metric names in persisted data; reference plan: `D:\laragon\www\_shared\data\aggregation-metric-equivalence-readonly-plan-2026-05-05.md`.
- Shared Phase A artifacts are now available in `api-driver-core` for this track (`CanonicalMetricDefinitionRegistry` + `CanonicalMetricDictionaryProviderInterface`), enabling Phase B integration of a canonical metric SQL resolver in `apis-hub`.
- During Phase B work, an unexpected test import regression was detected and corrected in `tests/Unit/Services/Aggregation/AggregationPlannerTest.php` (`Traits\AggregationPlanner` -> `Services\Aggregation\AggregationPlanner`), restoring planner regression coverage.
- A second unexpected test import regression was detected and corrected in `tests/Unit/Services/Aggregation/AggregationExecutorTest.php` (`Traits\*` imports -> `Services\Aggregation\*`), restoring executor regression coverage.
- Phase B now routes `MarketingHierarchyStrategy` metric expressions through `CanonicalMetricSqlResolver` using read-only precedence `override -> driver dictionary -> default`, preserving raw provider metric names in SQL predicates.
- `MarketingHierarchyStrategy` now appends `metric_resolution` metadata (`channel`, `strategy`, resolved canonical/raw metrics, source) into aggregate meta via repository strategy hook for telemetry/diagnostics.
- When canonical resolution fails in `MarketingHierarchyStrategy`, strategy metadata now sets `strategy_fallback_reason=missing_metric_equivalence`; `AggregationExecutor` consumes it as explicit legacy fallback reason with `executor_fallback_reason_source=strategy` when planner does not provide a fallback reason.
- Canonical dictionary pilot is now cross-channel: `CanonicalMetricSqlResolver` test coverage includes Meta and Shopify registry-provider resolution paths, reducing risk that the resolver is coupled to one driver family.
- Canonical dictionary pilot now covers a third channel (`klaviyo`), and resolver registry-path tests validate multi-channel behavior across Meta, Shopify, and Klaviyo providers.
- Google priority pilot is now complete (`google_search_console` dictionary-provider path validated), and pilot expansion is paused as agreed; next work should focus on remaining implementation-plan tasks rather than adding more pilot channels.
- Stable integration path is now formalized: overrides live in `config/aggregation/metric_equivalences.yaml`, operator/docs are in `docs/aggregation-metric-equivalence.md`, and executor telemetry persistence is covered for `metric_resolution` metadata.
- Ambiguous legacy marketing inputs are now explicitly classified during canonical resolution: stable aliases (for example `results -> conversions`) emit `input_type=legacy_alias`, while `actions` remains temporarily backward-compatible but is flagged via `metric_resolution.deprecation` as `ambiguous_metric_alias` instead of being treated as a canonical metric.

### 2026-05-05 - Session 2 & 3: Agnostic Aggregation Engine Finalization
- **Decisions**:
  - Eliminated `BaseRepository::getChannelKey()` to ensure repositories don't hold channel-specific knowledge.
  - Migrated `AggregationPlanner` to a fully agnostic matching model using account type suffixes (`_page`, `_account`) and `default_filters` from profiles.
  - Decoupled platform identity fields (e.g., `facebook_page_id`) by resolving them dynamically through `DriverFactory` and the `getPlatformEntityIdField()` driver contract.
- **Applied Changes**:
  - `AggregationPlanner.php`: Replaced hardcoded channel checks with profile-driven defaults and generic type matching.
  - `AggregationEntityFieldResolver.php`: Switched from `match($channel)` to dynamic driver lookup via `getPlatformEntityIdField()`.
  - `CanonicalMetricSqlResolver.php`: Removed hardcoded channel dictionaries; now relies on driver-provided mappings.
  - `BaseRepository.php`: Removed legacy `getChannelKey()` fallback and integrated agnostic identity resolution.
- **Next Steps**:
  - Clean up the `Strategies` folder by deleting the now-obsolete `FacebookOrganicStrategy.php`.
  ### 2026-05-05 - Session 4: Agnostic Discovery & Optimized Activation
- **Decision**: Implemented agnostic channel discovery in `AggregationPlanner` to resolve optimized paths for generic metric requests.
- **Bug**: The optimized path for Instagram account summaries (`facebook_organic_linked_pages_flow`) was failing to activate because the planner could not infer the channel without hardcoded logic.
- **Changes**:
    - `AggregationProfileResolver`: Added `resolveAll()` to allow the planner to search for matching profiles across all registered drivers.
    - `AggregationPlanner`:
        - Updated `evaluateProfileCapability` to discover the channel agnostically if not provided.
        - Relaxed `isMetric` entity restrictions to allow optimized strategies for the global metrics table.
        - Added the discovered channel to the `AggregationPlan` context.
    - `SocialOrganicStrategy` & `MarketingHierarchyStrategy`: Updated to retrieve the channel key from the plan context, ensuring correct SQL generation even without explicit channel filters.
- **Next Steps**: Validate the fix with the specific Facebook Organic payload reported by the user.

### 2026-05-05 - Orchestrator Modernization Phase 0
- **Problem**: Dedicated channel containers create deployment overhead and prevent granular account syncs without full restarts. Stuck jobs require manual cleanup.
- **Solution**: Move to an agnostic worker pool with dynamic scaling via Docker socket and atomic job resumption.
- **Actions**: Created implementation plan and handoff docs in `_shared`. Completed backend implementation for agnostic workers, auto-scaling, and granular sync.
- **Next Steps**: Implement Phase 5 (Monitoring UI Update) and expand granular sync support to other drivers.

### 2026-05-06 - Deployment Stability and OOM Resolution
- **Issue**: Containers (master, mcp, worker) were crashing with Code 137 (OOM) during Google Search Console config updates.
- **Cause**: `entrypoint.sh` was running `composer update` on every master start/restart, causing massive memory pressure. Also, the scalable worker pool lacked resource limits.
- **Fixes**:
    - Removed `composer update` from `entrypoint.sh`. Startup is now lean.
    - Added CPU/Memory limits to `master` (512MB), `worker` (384MB), and `mcp` (256MB) in `bin/build-deployment.php`.
    - Fixed MCP service discovery to use the `master` network name instead of `127.0.0.1`.
    - Added `--no-recreate` to `ScaleWorkersCommand` to prevent accidental service interruptions during scaling.
- **Protocol**: Dependencies must be managed during the build phase (Dockerfile) or manually, never during container startup.



### 2026-05-07 - Sync Telemetry Engine Implementation
- **Decision**: Implemented a Redis-cached telemetry API to track sync progress across all channels and assets.
- **Components**:
    - `SyncTelemetryService`: Calculates completion % using `JSON_EXTRACT` (MySQL) or JSONB operators (PostgreSQL).
    - `SyncStatusController`: Exposes `GET /api/sync/status` with API Key security.
- **Invalidation Strategy**: Event-driven invalidation via `JobRepository::update()`. Every time a job status changes, the relevant telemetry cache keys are purged.
- **Compatibility**: The telemetry service query dynamically switches syntax based on `Helpers::isPostgres()` to ensure platform parity.
- **Routing**: Routes are registered in `src/Routes/sync.php` and loaded via `bin/index.php`.
- **GSC Database Syntax Error**: Resolved "invalid input syntax for type integer: 'google_search_console'" by refactoring `ChanneledBaseRepository`. All channeled repositories now automatically resolve channel name strings to entity IDs in `findBy`, `findOneBy`, and `count` calls.
- **Meta Ad Account ID Prefix Normalization**: Updated the frontend `config-manager.js` to normalize ID comparisons by stripping the `act_` prefix before rendering saved checkboxes. Similarly, updated `ConfigManagerController::fetchAssets` to consistently strip `act_` when doing array/in-array checks on fresh vs previous assets (cached in `assets_backup.yaml`) to avoid false-positive 'is_new' or 'lost_access' duplicates.

### 2026-07-01 - History Limit Jitter & Job Rescheduling
- **Issue**: Historical jobs (e.g., Q1 2025) were being redundantly rescheduled daily when the rolling history limit date caused the start date to jitter backward (e.g., from March 2 to March 1 due to month-length variations).
- **Cause**: The `ScheduleInitialJobsCommand` logic strictly checked for `newStart > oldStart` to prevent shrinking-window reschedules, but did not tolerate backward jitter (`newStart < oldStart`), treating it as an expansion and forcing a full quarter reschedule via the `$isQuarterEnd` fallback.
- **Fix**: Updated `ScheduleInitialJobsCommand` to calculate `$startDiffDays` and tolerate up to 4 days of backward jitter when `oldEnd === newEnd`. This ensures that month-length jitter (Feb 28 vs Mar 2) at the edge of the history limit does not trigger redundant capstone syncs for fully closed, historical quarters.

### 2026-07-02 - Aggregation Telemetry Persistence
- **Issue**: The fallback aggregation telemetry report (`aggregation-telemetry.jsonl`) was losing data during the midnight transition.
- **Cause**: Standard log rotation policies or scheduled cron tasks were likely overwriting or purging the fixed `aggregation-telemetry.jsonl` file.
- **Fix**: Updated `AggregationTelemetryEventRecorder` to implement atomic daily file rotation by appending a date suffix (`aggregation-telemetry-YYYY-MM-DD.jsonl`) to the log path.
- **Support**: Enhanced `ReportAggregationTelemetryCommand`'s `--input` flag to accept a directory as input, automatically discovering and concatenating multiple daily `.jsonl` files for aggregate snapshot generation without breaking backward file compatibility.

### 2026-09-08 - Aggregation Filter Operators Enhancement (`not_in`, `like`)
- **Context**: Added support for advanced widget series and breakdown filtering capabilities from APIs Hub Facade.
- **Components**:
    - `FilterConditionResolver`: Added operator normalization for `not_in`, `not in`, and `!in` returning `['operator' => 'not_in', 'value' => ...]`.
    - `UniversalSqlStrategy`: Added `not_in` and `like` handling in `buildFilterClause()`.
    - For `not_in`: Implemented array placeholder expansion with NULL safety (`($col IS NULL OR $col NOT IN (:param_0, ...))`) and guard against empty lists (`1 = 1`). Supported across relational entity columns, ad accounts, and EAV dimension values.
    - For `like`: Added standard `$col LIKE :alias` clause generation and parameter binding.

### 2026-09-25 - MCP Predefined Reference Library, Derived Metrics & Dashboards Architecture
- **Reference Library Sync**: Facade's `PredefinedKpiRegistry` (40 KPIs) and `PredefinedDerivedMetricRegistry` (26 formulas) are embedded into `project_context.json` and served via `get_analytics_catalog` (`section: "predefined_kpis"` / `"predefined_derived_metrics"`).
- **Execution Protocol Documentation**: Updated `mcp-server/index.js` `get_mcp_guide`:
    - Added step 7 to `workflow` clearly explaining that agents evaluate complex/statistical AST formulas and Derived Metrics (DMs) by: (1) retrieving the formula/AST from the catalog or custom KPIs, (2) fetching constituent metrics via `summarize_performance`, and (3) computing the formula/operator over the returned series.
    - Added dedicated guide topic `kpi_calculation` with copy-paste recipes for `search_position_efficiency_query` and `true_blended_marginal_cost`.
    - Added dedicated guide topic `derived_metrics` clarifying the two tiers:
        1. Built-in Server Aggregations (10 SQL formulas like spend, clicks, impressions, ctr, cpc, cpm, roas, position, sessions, conversions).
        2. Reference Library AST Blueprints (26 DMs like cpa, cvr, click_position_efficiency, blended_cpc, aov, mer) that map channel placeholders (`__SEO_CHANNEL_1__`, `__SPENDABLE_CHANNEL_1__`) and evaluate ratios/operators across constituent series.
    - Added dedicated guide topic `dashboards` explaining dashboard structure, widget sources (`metric`, `kpi`, `derived_metric`), visualization widget roles (`tile`, `line_chart`, `bar_chart`, `pie_chart`, `gauge`, `sparkline`, `scatter_plot`, `combo_chart`, `table`, `anomaly_chart`), and guidelines for autonomous agent synthesis.
    - Updated `get_analytics_catalog` and `get_mcp_guide` tool schemas to include the new topic enums.
    - Added dedicated guide topics `benchmarks`, `temporal_comparison` (PoP & YoY velocity analysis), and `funnel_decomposition` (MECE root-cause diagnostic trees for ROAS and SEO).
    - Exposed `catalog.benchmarks` in `get_analytics_catalog` with section `benchmarks`.

### 2026-10-01 - EventProcessor Schema Alignment and UniversalEntity Order Routing
- **EventProcessor SQL Schema**:
    - Fixed PostgreSQL schema mismatch in `Classes\EventProcessor::processEvents()`. Table `channeled_events` does not have `name` or `type` columns; columns are `platform_id`, `event_id`, `channeled_account_id`, `channel`, `data`, `created_at`, `updated_at`.
    - Added channel resolution logic for string channel names to integer channel IDs via `Channel` repository lookup.
    - Updated unique constraint conflict columns to `['platform_id', 'channeled_account_id']` and update columns to `['event_id', 'channel', 'data', 'updated_at']`.
- **SyncService UniversalEntity Routing**:
    - Added `order`, `orders`, and `channeled_order` cases to `SyncService::processUniversalEntity()` dispatching to `Classes\OrderProcessor::processOrders()`.

### 2026-10-02 - Email & Messaging Metric Aggregation Resolution
- **CanonicalMetricSqlResolver & MetricDefaultFormulaBuilder**:
    - Added `sends`, `opens`, `bounces`, `unsubscribes`, and `orders` to `CanonicalMetricSqlResolver::SUPPORTED_CANONICAL_METRICS` and mapped them to SUM expressions.
    - Added default formulas for `sends`, `opens`, `bounces`, `unsubscribes`, and `orders` in `MetricDefaultFormulaBuilder`.
    - Resolved Mailchimp metrics via driver dictionary without triggering `missing_metric_equivalence_in_universal` or `missing_reducer_strategy`.

### 2026-10-06 - Campaign Type Filtering Parity in UniversalSqlStrategy
- **UniversalSqlStrategy Relation Filtering Fix**:
    - Fixed `SQLSTATE[22P02]: Invalid text representation` when filtering on relational table attributes or string arrays (e.g., `campaignType IN ('automation', 'automation-email')`).
    - Previously, if `$condition['value']` was an array of strings, `$isNonNumericString` evaluated to `false`, causing the query planner to mistakenly generate `mc.channeled_campaign_id IN ('automation', 'automation-email')` which failed PostgreSQL integer casting.
    - Updated condition check in `UniversalSqlStrategy` to check for `isAttribute` or non-numeric string arrays, properly joining the relation table (e.g. `channeled_campaigns rcc`) and filtering on `$alias.{$map['field']}` (e.g. `rcc.type IN (...)`).


- 2026-10-30: GA4 event expansion: channeled-event maps/resolution partition by account; discovery resolves accounts within channel. Same raw event name across properties cannot suppress insertion or select another property event ID. No automatic historic signature/link rewrite. See docs/google-analytics-expansion-plan.md for pending acceptance/rollout work.

- 2026-10-30: GA4 event maps and resolution partition by account; discovery account lookup partitions by channel. Identical raw event names across properties cannot suppress insertion or select another property event. No automatic historical link/signature rewrite.

- 2026-10-30 event cross-channel audit: current GA4 metrics populate channeledEvent; Mailchimp engagement/send/unsubscribe entities also route through EventProcessor. Offline regression uses installed Mailchimp converters and verifies hashed source IDs, payloads and channel-scoped audience resolution. Five-channel non-event metric-config persistence remains event-free with both event processors enabled. EventCrossChannelRegressionTest plus EventPropertyIsolationTest/MetricsProcessorTest: 15 tests, 91 assertions pass; no live tenant sync or PostgreSQL acceptance claimed.

- 2026-10-30 weighted aggregation performance: contract queries previously repeated correlated same-grain companion lookups in multiple CASE branches per metric row. UniversalSqlStrategy now shares a date/account/channel-bounded grouped companion scan, preserves full null-safe grain and missing/duplicate/negative guards, and prunes source names/period before aggregation. PostgreSQL equality/coalesce plus nullness guards allow hash joins without sentinel collisions. Validated contract plans report universal_sql instead of legacy missing-profile fallback diagnostics. Offline semantic/SQL/planner tests pass; PostgreSQL production latency requires EXPLAIN acceptance, not inferred from SQLite. Facade GA4 chart forwards daily groups even when widget granularity is trailing_monthly.

- GA4 expansion (2026-10-30, unreleased): generic admin scoped analytics endpoints queue definition-keyed whole source responses in persistent private storage/analytics-reports; master cron process-scoped-reports is required. Catalog/source calls stay in the worker, with authorization identities, property locks, budgets, quotas, row/byte limits and explicit staleness. Query contract supports driver-opted bounded trailing buckets and scope revision/dimension filter restrictions. Legacy daily grains remain unchanged; new optional scopes are revision1. See docs/ga4-scoped-reports-rollout.md for dependency order and required real-source/PostgreSQL canary; no production replay, pins or tag created.
