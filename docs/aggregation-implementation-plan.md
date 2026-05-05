# Aggregation Refactor - Implementation Plan

## Scope
This plan tracks the staged refactor of the aggregation flow in `apis-hub`.

## Canonical Background Plan
- Canonical source (shared): `D:\laragon\www\_shared\data\base-repository-aggregation-refactor-plan-2026-05-04.md`
- This file is the **execution snapshot for `apis-hub`** based on that shared plan.

## Current Status
- Overall status: **Phase 1 complete / Phase 2 started (local cleanup)**
- Last updated: 2026-05-04
- Tracking commit: `ca4eb62`

## Alignment with Shared Plan
- Phase 0 (instrumentation/baseline): **partially covered** in current repository-level metadata/fallback tracking.
- Phase 1 (planner/executor extraction + repository delegation): **complete**.
- Phase 2 (driver-core aggregation profiles): **not started in this repository scope**.
- Phase 3 (pilot channels): **not started under this document**.
- Phase 4 (optimized-first via feature flag rollout): **not started under this document**.
- Phase 5 (legacy retirement): **not started**.

## Phase 1 - Stage-driven legacy pipeline
Goal: keep behavior stable while decomposing the legacy aggregate path into explicit planner/executor/context/stages.

### 1) Planner + Executor routing
- [x] Add `AggregationPlan` value object
- [x] Add `AggregationPlanner`
- [x] Add `AggregationExecutor`
- [x] Route `BaseRepository::aggregate()` through planner + executor
- [x] Keep optimized-first, legacy-fallback semantics
- [x] Persist execution metadata (`planned_execution_path`, `execution_path`, `fallback_reason`)

### 2) Legacy execution context extraction
- [x] Add `LegacyAggregateExecutionContext`
- [x] Move legacy mutable execution inputs into context object
- [x] Remove parameter sprawl in legacy helper calls

### 3) Legacy stage extraction
- [x] Add `LegacyAggregateGroupingStage`
- [x] Add `LegacyAggregateFilterStage`
- [x] Add `LegacyAggregateDateStage`
- [x] Add `LegacyAggregateOrderingStage`
- [x] Add `LegacyAggregateFinalizeStage`
- [x] Add `LegacyAggregateRelationContextStage`
- [x] Add `LegacyAggregateSelectStage`
- [x] Add `LegacyAggregateScopeStage`

### 4) BaseRepository delegation
- [x] Delegate grouping to stage
- [x] Delegate filtering to stage
- [x] Delegate date constraints to stage
- [x] Delegate ordering to stage
- [x] Delegate finalization to stage
- [x] Delegate relation-context assembly to stage
- [x] Delegate select construction to stage
- [x] Delegate scope joins to stage
- [x] Add dedicated orchestrator `runLegacyAggregatePipeline()`

### 5) Tests and quality gates
- [x] Add/extend unit tests for planner/executor/plan/context
- [x] Add smoke tests for extracted stages
- [x] Replace deprecated `withConsecutive` usages in touched tests
- [x] Run focused phpunit validation for aggregation refactor

## Evidence executed in this phase
- Focused suite status (latest run): `OK (26 tests, 103 assertions)`
- Primary touched areas:
  - `src/Repositories/BaseRepository.php`
  - `src/Services/Aggregation/*`
  - `tests/Unit/Services/Aggregation/*`
  - `tests/Unit/Repositories/CampaignRepositoryTest.php`
  - `MEMORY.md`

## Out of scope for Phase 1
- No behavior redesign of optimized SQL strategies.
- No broad formula rewrite.
- No API contract changes.

## Next phase candidates (not started)
- [~] Phase 2: move remaining legacy SQL semantic helpers from repository into reusable stage services where safe.
  - [x] Extract temporal gap filling into `Services\Aggregation\TemporalGapFiller` and delegate from `BaseRepository`.
  - [x] Extract snapshot aggregate metadata extraction into `Services\Aggregation\SnapshotAggregateMetaExtractor` and delegate from `BaseRepository`.
  - [x] Extract filter condition parsing into `Services\Aggregation\FilterConditionResolver` and delegate from `BaseRepository`.
  - [x] Extract base date SQL field fallback selection into `Services\Aggregation\DateSqlFieldResolver` and delegate from `BaseRepository::mapFieldToSql()`.
  - [x] Extract temporal date-part alias SQL mapping into `Services\Aggregation\TemporalDatePartSqlResolver` and delegate from `BaseRepository::mapFieldToSql()`.
  - [x] Extract default metric formula construction into `Services\Aggregation\MetricDefaultFormulaBuilder` and delegate from `BaseRepository::getDefaultFormulas()`.
  - [x] Extract metric period SQL condition generation into `Services\Aggregation\MetricPeriodConditionSqlResolver` and delegate from `BaseRepository::getMetricPeriodConditionSql()`.
  - [x] Extract companion weighted-average SQL assembly into `Services\Aggregation\CompanionTimeWeightedAverageFormulaBuilder` and delegate from `BaseRepository::buildCompanionTimeWeightedAverageFormula()`.
- [ ] Phase 2: tighten planner telemetry and fallback analytics for production monitoring.
- [ ] Phase 2: evaluate executor-owned dependency wiring to reduce repository orchestration surface.

## How to continue (new sessions / other agents)
Use this order to resume work with minimal context loss:

1. Read `AGENTS.md`.
2. Read `MEMORY.md`.
3. Read this file: `docs/aggregation-implementation-plan.md`.
4. Read canonical shared plan: `D:\laragon\www\_shared\data\base-repository-aggregation-refactor-plan-2026-05-04.md`.

At the end of each session, create or update a handoff note using:
- `docs/session-handoff-template.md`

Recommended naming convention for handoff artifacts:
- `docs/handoffs/aggregation-YYYY-MM-DD-session-<n>.md`

Minimum handoff content:
- Completed in session
- Pending next actions
- Risks/assumptions
- Validation commands and results
- Files touched and commit SHAs

