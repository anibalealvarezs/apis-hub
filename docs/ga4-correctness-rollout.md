> Subsequent expansion: [scoped report implementation and rollout](ga4-scoped-reports-rollout.md)
> now adds whole-window users and weighted trailing buckets. Historical statements
> below describe the earlier correctness increment.

# GA4 correctness rollout

Implementation date: 2026-10-30. No production upgrade or source replay has run.

## Implemented behavior

- GA4 driver publishes query contract v1 in its aggregation profile. Tenant applies
  the contract before execution, defaults unscoped requests to traffic_matrix and
  rejects unsupported metrics, mixed scopes and incompatible breakdowns. Explicit
  `dimensions.scope is_null` selects the legacy account baseline.
- Advertising cost and cost-dependent ratios are unavailable for GA4. The canonical
  spend dictionary entry is explicitly empty, and the resolver treats an empty driver
  entry as unsupported instead of falling back to a generic alias. Revenue is intact.
- Rates use same-date, same-period, complete-grain session companions. Missing,
  duplicate or negative companion weights produce NULL, not an unweighted average.
  Contract execution cannot fall through to legacy formulas. Calendar grouping is
  supported; rolling windows are explicitly rejected until their reducers are defined.
- User counts are sums of reported daily/slice counts, explicitly marked as non-unique
  in result metadata and Facade labels/notices. Exact arbitrary-window unique users
  are not implemented; no user-level identities or live per-widget provider queries
  are introduced.
- Source/medium grouping and filtering compose existing split dimensions through a
  driver-declared virtual dimension. Equality, exclusion and pattern filters retain
  their combined-string intent. No dimension-set or signature migration is needed.
- Property metadata selects keyEvents or the supported legacy conversions name;
  storage retains the existing conversions identity. Requested report fields pass
  checkCompatibility. A failed matrix throws, allowing job retry instead of success
  on partial ingestion. Already persisted successful rows remain idempotent on retry.
- New observations retain allowlisted thresholding, sampling, currency/timezone and
  schema-restriction metadata. Revenue totals across multiple properties require
  known matching currencies for all contributing observations; otherwise the report
  fails with an actionable error. Single-property history remains queryable.
- Facade acquisition choices omit revenue; Explorer and KPI metric choices share its
  catalog. Failed pooled scopes and forwarded widget errors cannot silently become
  empty successful totals. Explorer keeps unavailable rates as NULL/dashes. GA4
  widgets display the user-count notice, including configured KPI/derived sources.
- GA4 browser cache keys and widget result hashes are versioned. The renderer browser
  cache salt changes once for all widgets so old cached responses do not hide fixes;
  existing request batching/backpressure remains in place.
- Unavailable weighted inputs carry explicit aggregate metadata. KPI hydration rejects
  them before its legacy numeric coercions; raw widgets likewise show an unavailable
  input error rather than formatting a missing rate as zero.

## Deployment order and historical impact

1. Publish the Google SDK compatibility endpoint and optional core contract support.
2. Publish the Google driver containing both source validation and query policy.
3. Release the tenant with that driver and contract executor together. The tenant
   resolver preserves query_contract even with an older profile normalizer during
   staged dependency upgrades. Do not deploy the driver policy without its executor.
4. Upgrade controlled tenants, verify readiness and representative reports, then
   deploy the corresponding Facade/UI changes. Do not allocate or change an already
   released version/tag to ship these changes. Composer pins require the release step.

Existing GA4 revenue observations must not be rewritten as cost. Removing the alias
is read-time only. Existing complete rate/session pairs work immediately without a
backfill. Where weights are missing, rates remain unavailable until eligible daily
windows are re-fetched. New source-quality metadata cannot be reconstructed from
old values; multi-property revenue over old windows may require a targeted replay
to establish matching currencies, or users can query one property at a time.

Inventory saved cost-based GA4 KPIs/widgets and scope-less acquisition requests;
flag unsupported definitions for correction rather than replacing their meaning.
Check for manually configured historic raw `keyEvents` configurations before replay:
the default cache identity remains `conversions`; do not simply sum both names or
rewrite configuration signatures without duplicate reconciliation. No migration of
custom legacy identities is silently attempted.

If historical replay is needed, use the existing GA4-only scheduling procedure
without wiping metrics, after reviewing configured properties, windows and API quota.
Validate idempotence on a copied database first, and schedule during low activity
outside normal sync hours. API metadata and compatibility preflights add source
requests per job; use the existing SDK rate limits and worker budgets.

## Acceptance and remaining operational work

Offline tests cover source-name resolution, quality/missing versus zero conversion,
failed report propagation, policy normalization/resolution, pre-execution cost/scope
rejection, weighted SQL, virtual dimension filtering, currency guards, Facade
availability/errors and existing aggregation/GSC behavior. SQLite exercises portable
SQL semantics; SQL/mocks are not PostgreSQL scale or authenticated source validation.

The older GoogleAnalyticsDriverTest suite also has two existing failures in
testUpdateConfiguration and testGetAssetPatterns. Both were reproduced against the
unchanged HEAD driver in isolation; they are not introduced by this increment.
Keep these legacy interface expectations separate from the focused correctness gates.

Before broad rollout, run the following against a representative PostgreSQL copy
and one controlled property:

- Compare source and cached totals under matching property timezone, currency, date
  range, attribution and scope. Verify metadata names and compatibility responses.
- Measure weighted-companion query plans/latency on large configurations and long
  ranges. Shared API budgets remain mandatory; stop rollout if dashboards regress.
- Check saved KPI/widget validation, public notices, missing-rate presentation,
  multi-property currency failures, retry behavior and absence of duplicate ingestion.
- Re-fetch only the eligible missing-weight/metadata windows, then verify counts,
  signatures and values. Keep irrecoverable/missing history explicit.

Source-threshold metadata is retained for diagnosis but is not yet summarized into
per-report UI badges. Exact-window user reporting and provider-driven expansion of
additional metrics remain separate follow-ups requiring a budgeted window-cache
design and controlled provider verification. No claim of complete live parity is made.


## Weighted-query performance follow-up

Weighted rate/duration aggregation now uses one grouped companion scan per weight
metric set, bounded by the requested dates and direct account/channel predicates.
It joins on the complete cached grain, counts duplicate observations and retains
NULL for missing/invalid weights. Multiple weighted metrics sharing sessions reuse
that scan. Source metric names and daily period are filtered before aggregation.
This replaces repeated correlated subqueries on the contract-aware SQL path; it
does not loosen rate semantics or use account/date-only weights.

Planner diagnostics identify the validated contract execution capability and the
actual universal_sql candidate, rather than claiming a legacy profile fallback.
Queries without a driver contract retain the existing planning behavior.

Offline regression covers weighted results, missing/duplicate/negative weights,
zero weights, grain isolation, shared SQL scans and planner diagnostics. SQLite
EXPLAIN confirms the joined formulation has no correlated subquery. Before rollout
acceptance, compare PostgreSQL EXPLAIN (ANALYZE, BUFFERS) and latency for the same
six-property, August–October duration request and check result equality. No live
production benchmark or deployment is implied by the offline checks.
