# Aggregation regression: code-only correction

## Commit comparison

- Parent of 216e94f7: contract weighted reducers used correlated companion formulas.
- 216e94f7: replaced them with a derived companion dataset grouped by date plus all
  persisted grain columns, joined back to source facts. It also changed diagnostics
  for contract queries. This is the join shape responsible for the investigated
  GA4 work; indexing/planner statistics were not a substitute for fixing it.
- 58386697: added contract-only trailing buckets, revision/filter validation and a
  separately scheduled source-report worker. Its temporal change does not apply to
  the supplied summary or non-contract GSC request.
- WeightedMetricStrategy and AggregationExecutor are byte-identical to the parent
  of 216e94f7. The reported GSC query+intent/Web/standard request is covered by a
  planner fixture using installed driver profiles and remains on optimized routing.
  That routing check does not establish GSC production latency or rule out shared
  API/database contention from slow GA4 requests.

## Measured failed experiment

The lateral indexed-lookup benchmark took 44.7 and 39.1 seconds, with 15,306 weight
lookups and over nine million buffer hits. It is rejected, removed from code, and
was never enabled in the executor. Restarting the application could not change the
standalone benchmark's SQL. Do not interpret activity predicate flags as proof of
old loaded code: pg_stat_activity may truncate SQL before those predicates.

## Replacement

PostgreSQL contract queries with weighted reducers now build a materialized fact CTE.
A named window partitions by the full persisted grain and date, counts companion
observations and retains their value. Both rates reuse the same window. Outer SQL
keeps existing filters, groupings, additive sums and currency guards. Source-only
metric pruning happens outside the window so unselected weights remain available.
There is no wide companion self-join and no per-observation lateral lookup. Null,
zero, negative, duplicate and missing weights retain the established behavior.

Non-contract and other-channel strategy selection is unchanged. Non-PostgreSQL
execution retains the existing compatible path. No statistics command, DDL,
migration, index, resync or data repair is required or run by this patch. The pending
manual statistics-maintenance command and registration have been removed.

## Validation

The local 45,000-observation fixture executes both weighted rates and additive
sessions using one physical fact scan. SQL-plan assertions check materialization
and absence of correlated lookups. Numerical regression compares window execution
with existing formulas, including missing/duplicate/negative/zero companions and
campaign/date isolation. The reported GSC planner shape has its own regression.
These SQLite/unit results are not a PostgreSQL production latency claim.

The read-only `docs/sql/ga4-traffic-performance-audit.sql` now reflects the current
windowed compiler, replacing the old self-join template. Its default EXPLAIN mode
only plans; optional analyze=true executes inside a read-only transaction with a
45-second timeout. It changes no schema or metrics. No further manual database
alterations are part of this fix.
