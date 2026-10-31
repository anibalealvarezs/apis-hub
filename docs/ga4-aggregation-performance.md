# GA4 Explorer aggregation performance correction

## Evidence (2026-10-31)

Captured long-window Explorer SQL uses the contract UniversalSql executor; it is not
falling back to the legacy reducer. Traffic requests add the same-grain sessions
companion scan; acquisition queries do not. The captured SQL omitted channel from
both scans even though it was supplied in the request. Existing composite config
indexes mostly lead with channel, and none of the supplied indexes covers LOWER(name)
and LOWER(period) as expressions. The companion scan was date/account-bounded but
included every scope, then joined its result to additive as well as weighted rows.
Logs contain completed results but no query start/duration or PostgreSQL EXPLAIN;
they do not establish which database operator accounts for the timeout.

## Code changes

- Compile channel-name/ID membership to a database predicate, including the companion
  scan. Channel names are resolved using the channels name index; no ambient ORM
  lookup or driver-specific numeric ID is required. Bound parameters preserve names,
  numeric IDs, exclusions and mixed membership without injecting literals.
- Push the driver's declared equality scope into the companion scan as a dimension
  set semi-join. Other-channel/other-scope sessions cannot inflate the intermediate
  scan. No scope names are hardcoded in the generic executor.
- Probe companion results only for the union of weighted source metric names sharing
  that companion. Additive metrics stay on their existing sum path. Full null-safe
  grain matching and missing/duplicate/negative-weight guards remain unchanged.
- Log `Completed SQL: duration_ms=... rows=...` after the database fetch.

No cached metrics or signatures are changed. No historical resync or schema migration
is required for this code correction. This does not assert that all existing indexes
are optimal: PostgreSQL execution plans and real timings are still required before
recommending additional expression/covering indexes or declaring the 504 fixed.

## Read-only database acceptance

The accompanying SQL file reproduces the corrected captured Traffic Pages query,
with property and dates supplied as psql variables. Copy the file to the tenant if
it is not present in its deployed checkout. From the tenant directory:

```bash
docker compose exec -T db sh -lc \
'psql -U "${POSTGRES_USER:-postgres}" -d "${POSTGRES_DB:-postgres}" -v ON_ERROR_STOP=1 -v account_id=46 -v date_start=2025-01-01 -v date_end=2026-10-29 -v analyze=false' \
< docs/sql/ga4-traffic-performance-audit.sql
```

Default mode plans without executing. During a quiet period, `-v analyze=true` runs
EXPLAIN ANALYZE/BUFFERS inside a read-only transaction with a 45-second timeout. Do
not run concurrent diagnostic copies while Explorer requests are already stuck.
Compare selected config/metric row counts, sort spills, buffers and join shape;
then rerun the four original Explorer payloads after deploying/restarting the tenant.
Inspect the new duration log for both summary periods and each table. If requests
still time out, capture their execution plans before choosing another optimization.

Offline regression uses the long-window shape with multiple channels/scopes, mixed
additive/weighted metrics, duplicate/missing companions and parameterized channel
membership. It proves isolation and arithmetic, not production PostgreSQL latency.
The broad aggregation unit suite also has two existing SocialOrganic sentinel tests
expecting a metadata callback that the unchanged early-return path does not emit;
those unrelated tests were left intact.


## Execution architecture and cross-channel scope

The omitted channel predicate was a shared UniversalSql compiler defect: channel was
recognized as a standard field (so it bypassed dynamic dimension filtering), but no
filter branch emitted its predicate. The correction applies to every channel using
UniversalSql, not just GA4. Companion pruning applies to contracts declaring a scope
and companion-weighted reducers. Other specialized strategies are separate code paths;
passing UniversalSql regressions is not proof that every channel/query is fast.

Architecture direction confirmed by the operator: retire legacy aggregation execution
as capability coverage becomes complete. Legacy references describe current temporary
compatibility paths, not a preferred long-term executor. Currently contract queries
execute UniversalSql directly and fail explicitly when unsupported; non-contract
queries can still select specialized SQL strategies or fall back to legacy. Migrate
snapshot/latest/delta behavior and other remaining capabilities with semantic parity
and execution-plan tests before removing that fallback. UniversalSql is the shared
optimized target, but its name alone does not establish a good PostgreSQL plan.
