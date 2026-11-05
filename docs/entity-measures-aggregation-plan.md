# Entity measures aggregation

**Status** · Proposed

**Delivery track** · Worker aggregation capabilities, SDK consumption and progressive Facade integration.

Provide measures computed from cached entities, including customer populations and, when commerce coverage is ready, order counts, revenue and average order value. Queries can span authorized assets across several channels. Extend the existing aggregation planner with an entity strategy and use the shared analytics discovery and response conventions.

## Relationship to entity pages

The entity pages track establishes stable entity references, authorized project and asset scope, typed filters, provenance, and source-time semantics. Agree on that foundation together, then develop the tracks in parallel. Entity pages can ship through their read APIs and existing metric queries. Each entity-measure capability can enrich those pages in a later release. [Marketing entity pages plan](../../apis-hub-facade/docs/plans/marketing-entity-pages.md)

## Scope and metric semantics

An entity measure declares its source records, identity grain, reducer, eligible population, supported dimensions, time semantics, currency policy, definition version and required coverage. Channels are optional filters or breakdowns for entity measures. Provider-reported measures retain their declared channel reporting scopes.

| Measure | Source and grain | Time interpretation |
| --- | --- | --- |
| Current customer count | Verified base customer identity across visible memberships | Latest synced state |
| Customers created in a period | Verified customer identity and a defined canonical creation date | Source creation cohort |
| Source-profile count | Channeled customer record within its asset namespace | Current state or source creation cohort, as declared |
| Orders by shipping country | One verified order identity across selected stores and platforms | Order placement date |
| Order revenue | Eligible authoritative order amounts within a currency | Declared order date and refund policy |
| Average order value | Matching eligible revenue and order count | Same scope, period and currency for both components |
| Historical customer population | Retained identity and membership snapshots or change history | State as of a requested date |

The creation date of a canonical customer needs an explicit policy across source profiles, including the effect of newly discovered older records. Current attributes applied to past facts must be labeled as current-attribute filtering. Historical state and historical attribute segmentation require retained evidence.

Cross-channel coverage alone does not make a measure derived. Order count is a base measure even when its records come from several platforms. Average order value composes revenue and count as a derived measure. Composed KPIs declare compatible scope, identity, time and attribution semantics for their inputs.

## Architecture

- Extend the existing planner and executor with an entity aggregation strategy. Reuse authorization, typed filter conventions, request budgets, cache coordination and result metadata. Audit generic entity aggregate endpoints and repository support before choosing reusable query paths.
- Drivers declare supported source mappings and capabilities. The worker owns source-independent entity execution and physical query planning. Typed catalog fields define eligible grouping and filter expressions.
- Start with bounded live queries against cached entity records. Report definition version, execution source, synchronization freshness, coverage and unavailable capabilities in the response.
- Apply permitted asset scope before selecting visible identity memberships and records. Resolve source namespaces and verified core identities explicitly. Distinct customer counts and order identity selection must survive overlapping asset groups and multiple source representations.
- Select the authoritative order representation before counting orders or summing revenue. Declare billing, shipping and customer-country dimensions separately. Use an explicit unknown-country bucket where appropriate.
- Aggregate each fact grain before joining many-to-many relationships. A product or source-membership join must preserve the intended count and revenue allocation. Validate the sum of grouped results only for dimensions whose buckets form a partition.
- Keep provider purchase revenue and canonical order revenue separately discoverable with their definitions and provenance. Compose them through declared relationships when a KPI requires both.

## Delivery phases

### 0. Shared foundation and measure contract

- [ ] Agree on the shared read foundation with the pages track and its versioned API representation.
- [ ] Audit existing entity aggregate routes, repository builders, planner strategies and filters. Record the reusable components and required strategy changes.
- [ ] Define entity-scoped requests with project, asset selection or asset group, optional channel selection, measure IDs, typed filters, groupings and declared time semantics.
- [ ] Define discovery, errors, freshness and coverage metadata. Require measure capability discovery so Facade can adopt each measure incrementally.

Acceptance requires a reviewed contract usable by both pages and widgets, including a request spanning several channels and a tenant with read capabilities but no entity-measure capability.

### 1. Current customer populations

- [ ] Implement current customer count and customers created in a period using bounded queries over cached records. Keep canonical customer count and source-profile count separately selectable.
- [ ] Reuse the pages track's permitted scope and typed filters. Agree on the first supported current customer dimensions and canonical creation-date policy.
- [ ] Add query budgets, actor/scope-aware caching and invalidation on relevant sync changes, identity merges, deletions and access changes. Return unavailable coverage explicitly.
- [ ] Expose the capability through the SDK and consume it in a separately releasable Facade enrichment.

Acceptance requires agreement with independently counted fixtures, correct empty scopes, deduplication across visible source memberships, and access-revocation isolation.

### 2. Broader entity measures

- [ ] Add supported measures for the released entity types according to verified data coverage.
- [ ] Schedule commerce measures after an integration has verified order coverage and identity relationships. Define order identity across platforms and select an authoritative source representation.
- [ ] Define eligible order statuses, cancellations, refund timing, taxes, shipping, source dates and currency. Implement order count and revenue first, then average order value from matching components.
- [ ] Verify orders by shipping country across several stores and platforms. Keep source-only records queryable when canonical identity cannot yet be established, with their counting semantics explicit.

Acceptance requires repeated provider order IDs in different stores to remain distinct, verified mirrored orders to count once, and grouping joins to preserve the intended fact grain.

### 3. Derived metrics and Facade integration

- [ ] Reuse the existing derived-metric/KPI composition path where its contracts are compatible. Validate component scope, time semantics, currency, attribution and identity before execution.
- [ ] Add entity measures to widget discovery, profile summaries and Marketing Overview through capability gates. Reuse measure definitions across these consumers.
- [ ] Verify that already released pages remain usable when a measure is unavailable, coverage is incomplete or an aggregation request fails.

### 4. Selective materialization

- [ ] Measure PostgreSQL query latency and scan cost on representative tenant volumes. Add targeted indexes or materializations when justified by those measurements.
- [ ] Preserve sufficient reducer state. Store revenue and count for average order value. Exact distinct counts across overlapping scopes require identity membership, or a separately declared approximate representation.
- [ ] Match materializations by definition version, identity grain, dimensions, time and scope. Use the live strategy when a rollup cannot satisfy the request.
- [ ] Rebuild affected old and new partitions when records move or change eligibility. Handle late records, updates, deletions, retries and concurrent sync. Publish completed revisions atomically and invalidate affected caches.

Acceptance requires live/materialized parity, repeat rebuild stability, rollback on partial failure, and correct updates after a record changes country, status or source membership.

### 5. Historical entity state

- [ ] Choose retained snapshots or change history for agreed historical questions and define retention, effective timestamps and late-correction rules.
- [ ] Expose as-of population and historical-attribute filters only for covered periods. Return coverage and gaps explicitly.

## Release checks

- [ ] Channel-specific metric requests preserve their established behavior while entity requests can span channels.
- [ ] Worker enforcement and cache keys preserve tenant, actor and asset authorization on every execution path.
- [ ] Repeated sync, identity merges and overlapping memberships preserve the declared counting grain.
- [ ] Sum, count, distinct count and ratios obey their reducer rules across requested groupings and windows.
- [ ] Currency handling rejects incompatible combinations or uses an explicitly defined conversion policy.
- [ ] Current-state, creation-cohort and as-of queries expose their distinct time semantics.
- [ ] Deploy worker capability support, then SDK support, then the consuming Facade enrichment.
