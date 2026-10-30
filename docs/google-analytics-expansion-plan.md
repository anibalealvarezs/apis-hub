# GA4 scoped analytics expansion plan

Created: 2026-10-30. Status: proposed; expansion implementation has not started.
Baseline: v1.18.4 correctness work and the existing purpose-driven GA4 matrices.
This plan extends the [GA4 analysis](google-analytics-analysis-and-implementation-plan.md)
and [rollout requirements](ga4-correctness-rollout.md); it does not alter v1.18.4.

## Product objective

Cache useful marketing reports reliably: acquisition, traffic quality, custom events,
lead generation, ecommerce and selected advanced analyses. Expand the existing
purpose-driven scopes instead of attempting one universal, exhaustively broken-down
GA4 dataset. A field being offered by Google does not mean every report can use it,
or that our cache can answer every combination involving that field.

Every increment must update the GA4 metrics catalog, driver definitions, tenant query
contract and relevant Explorer/widget choices together. Catalog maintenance is part
of implementation and release acceptance, not a documentation task deferred to later.

## Non-negotiable design constraints

### Nine dimensions per source report

The Data API permits up to nine requested dimensions. A requested date dimension
uses one slot; property identity supplied in the request path does not. Validate the
actual generated request, including implicit dimensions, against the limit before
scheduling work. Pagination and batching do not increase the per-report dimension
budget. Compatibility checks and acceptable cardinality remain separate requirements.

Create bounded report recipes for specific questions. Do not enumerate all dimension
subsets or all possible custom-field combinations. Different reports are separate
datasets, not fragments that can generally be joined to recover a larger joint cube.

For example, cached country/device results and separate country/campaign results
cannot establish campaign-by-device results, even though both contain country.
Reject unsupported joint filters/breakdowns or schedule a dedicated compatible
report; never manufacture the missing distribution or assume independence.

A narrow optimization is worth testing: request exactly one property-local day,
omit the constant date dimension and inject the known date as cache context. Similarly,
an explicitly fixed event predicate may serve as context in a dedicated event report.
Validate source semantics, compatibility and identity before adopting either approach.
They trade more scoped requests for slots; they do not permit unrestricted joint
breakdowns. Preserve fixed predicates in signatures and report provenance. Do not
silently drop dimensions or fan out across every value to bypass the limit.

Source-side concatenation is not a planned escape hatch: Google's documented expression
inputs must reference dimensions in the request, and delimiters can be ambiguous.
Our existing virtual Source / Medium composes fields already cached together; it
does not recover information from independently aggregated reports.

### Scope and metric semantics prevent duplicated numbers

Distinguish these concepts in the contract:

| Concept | Examples | Why it matters |
|---|---|---|
| Report purpose | Traffic, first-user acquisition, event performance, product sales | Determines which business question a dataset answers |
| Measurement scope | User, session, event, item | Sessions and users are different populations; sharing a dimension does not make them interchangeable |
| Attribution scope | First user, session, key-event attribution | First-user campaign and session campaign are different explanations of performance |
| Temporal behavior | Daily flow, requested-window distinct, snapshot | Determines whether dates can be summed, queried as one window or selected as latest |
| Grain and fixed predicates | Date/event/page; one property; fixed event name | Defines which combined filters and breakdowns the observation can actually answer |
| Additivity | Additive over verified axes, non-additive over others | API compatibility alone does not prove safe rollup |

Each metric declares its reducer, numerator/denominator or weight, units, compatible
report recipes, and verified additive axes. Do not globally assume that sessions are
always additive, or that users become additive when grouped by date. A metric can
appear in multiple overlapping reports without those reports being summable.

The planner chooses one authoritative dataset per requested measure and query shape.
It must not add baseline totals to detailed rows, add traffic and acquisition versions
of the same metric, or deduplicate legitimate overlapping user membership by guessing.
Display totals are not automatically the sum of breakdown rows. Request/cache the
appropriate source total when breakdown membership overlaps.

Sessions by session campaign and users by first-user campaign may be presented side
by side only with explicit attribution labels and verified entity mapping. Do not
silently propagate a filter from one attribution scope into another, or join reports
on campaign names and claim a shared population.

### Provider-neutral engine, driver-owned definitions

Keep GA4 source names and compatibility in the Google driver. Shared core/tenant
contracts describe grain, scope, additivity, coverage and supported query operations.
Extend the v1 query contract incrementally and version changes; no GA4-specific SQL
branches in the generic aggregation engine. The effective tenant catalog drives
Facade controls and server validation, including direct API consumers.

## Milestones and order

| Phase | Deliverable | Principal packages | Depends on |
|---|---|---|---|
| 0 | Baseline coverage map and regression fixtures | Google driver, apis-hub, Facade | Existing v1.18.4 behavior |
| 1 | Complete event discovery and reporting | Google SDK/driver, apis-hub, Facade | 0 |
| 2 | Property-specific capability catalog and scoped report recipes | Core, Google driver, apis-hub/API, Facade | 0; incorporates 1 |
| 3 | Configurable custom dimensions and metrics | Same capability/reporting stack | 2 |
| 4 | Broader traffic/engagement and ecommerce coverage | Google driver, apis-hub, Facade | 2; custom fields from 3 when selected |
| 5 | Exact-window user reports and visible data quality | Google SDK/driver, apis-hub, Facade | 2 and bounded request scheduler |
| 6 | Dedicated funnel/cohort capabilities; optional warehouse investigation | Relevant SDK/driver and reporting consumers | Explicit use cases and preceding contracts |

Deliver coherent increments, not one all-channel migration. Phases 1 and 2 are the
first implementation tranche. Do not wait for advanced analysis before fixing event
discovery or keeping the catalog accurate.

## Phase 0 — Establish the coverage baseline

- Trace source request, conversion, entity resolution, persistence, aggregation and UI
  for each current metric/scope. Record supported combinations, not just field names.
- Add ordinary-event, custom-event and key-event fixtures, including zero key events,
  names sharing a canonical marketing classification, same event name in two
  properties, missing fields, late data and partial failures.
- Test overlapping traffic/acquisition reports and user counts across dates, pages
  and event names. Assert that totals do not become sums of overlapping observations.
- Identify current nine-dimension recipes and which actual user questions they cannot
  answer. Estimate property-specific row cardinality and source requests per window.
- Reconcile the two legacy GA4 driver-test expectations identified in the correctness
  rollout notes against the current supported interfaces; do not change assertions
  simply to hide product defects.

Exit: a catalog coverage matrix identifies every supported metric/recipe, its exactness
and missing path. Initial event fixes have reproducible failing end-to-end fixtures.

## Phase 1 — Finish ordinary and custom event support

The current event matrix requests eventName/eventCount, but separate entity discovery
still uses legacy conversions and retains only positive conversion events. Fix the
entire path, not just the report metric list.

- Discover observed events through event counts, independently of key-event status.
  Use the shared metadata/compatibility pipeline in discovery as well as metric sync.
- Preserve the original event name and property association. Canonical marketing
  classification may enrich an event but must not collapse distinct source events.
- Treat event count and key-event count as separate measures. An ordinary custom
  event with zero key events must still be cached, selectable and filterable.
- Verify event/channeled-event upsert and relationship resolution for newly observed
  names, pagination, cancellation and idempotent retries. Propagate failed discovery
  instead of ignoring its error response and claiming full success.
- Exercise event selectors and filters in Explorer, raw/custom/derived/KPI widgets
  and public dashboards using the same scope contract and asset authorization.

History: inspect existing event references and retained source identity first. New
discovery does not guarantee that old unresolved metric relationships are repairable
without re-fetching. Reconcile retained identities when provable; otherwise schedule
only affected property/date windows, with no global wipe or signature rewrite by default.

Exit: a non-key custom event such as request_quote appears with its true count and
zero key events; its name survives display/filtering; replay changes neither identities
nor totals. Same-named events in different properties do not collide. Catalog and
UI describe event and key-event support accurately.

## Phase 2 — Property-specific catalog and report recipes

Use property metadata to discover source fields. Cache it with a refresh policy,
permission-aware failure state and definition revision. Metadata discovery identifies
fields, not event occurrences, cached history or safe metric combinations.

The catalog must distinguish source-supported, property-available, implemented,
selected for sync, cached for a window, and reportable for a requested query. Include
friendly labels, raw names, types, units, measurement/attribution scopes, reducers,
recipe membership, fixed predicates, dimension budget, coverage and exactness.

Define a bounded recipe set rather than a universal cube. Candidate purposes:

| Recipe family | Questions | Scope constraints |
|---|---|---|
| Property totals | Overall traffic, engagement and revenue | Source totals, not sums of overlapping report families |
| Session acquisition | Which channels/campaigns bring sessions? | Session attribution; declared compatible dimensions |
| First-user acquisition | Which sources originally acquired users? | First-user attribution; distinct-user limits |
| Content performance | Which landing pages/content perform? | Distinguish session landing pages from event page paths |
| Event performance | Which events happen and where? | Event counts, key events and selected event context |
| Product performance | Which products/categories sell? | Item grain separated from transaction/event totals |

For each recipe, record the actual API dimension list (at most nine), metric set,
source filters, local context, grain, total strategy and unsupported joint queries.
Validate compatibility before work is scheduled. Multi-property choices use a
compatible intersection or explicitly separated series; never silently drop fields.

Extend query planning to select a recipe that jointly covers the requested measure,
breakdown and filters. If none exists, explain the gap and optionally offer a budgeted
new report definition. Never join marginal reports into an invented joint dataset.

Exit: source-only fields cannot appear as ready-to-query metrics, incompatible
requests fail before source work, and Explorer/widgets/API agree on available queries.

## Phase 3 — Custom dimensions and custom metrics

- Let users select registered custom fields offered by each property, with scope,
  friendly labels and compatible recipe choices. Do not automatically ingest every
  parameter or generate a Cartesian product of custom fields.
- Support bounded custom report definitions, each with an explicit nine-dimension
  budget and cardinality estimate. Distinguish event parameters from stored top-level
  entity attributes: their filtering semantics and historical availability differ.
- Preserve source identifiers and definitions through renames/removals. A removed
  field is not a measured zero. Retain historical schema/version information.
- Handle custom sum, average and count variants separately; do not average averages
  or infer exact distinctness from numeric metadata alone. Unverified reducers stay
  unavailable until a semantic contract and reconciliation are supplied.
- Persist a definition hash covering property, fields, scopes, recipe version and
  predicates. A grain change creates a new versioned dataset, not extra rows added
  to the old total. Plan explicit cutover/reconciliation for saved reports.

History: new fields need targeted backfill if past reporting is desired and the source
can return it. Do not promise availability before registration or beyond available
source history. Existing reports keep their old definition until an explicit cutover.

Exit: a selected custom event parameter and custom measure work end to end, consume
their declared slots, retain correct units/reducers, and cannot contaminate another
property or silently change a saved widget's meaning.

## Phase 4 — Expand marketing coverage by purpose

Traffic/engagement candidates include engaged sessions, engagement rate, engagement
duration and useful channel/content measures. Ecommerce candidates include purchases,
purchase revenue, refunds, product views, add-to-cart, item quantities and item revenue.
These are candidates, not claims of current support; verify source names, scope and
compatibility at implementation time.

- Add only fields with defined semantics, source availability, cache recipes, reducers
  and consumer support. Preserve denominator/component observations for rates.
- Keep event-level purchase/revenue and item-level product measures separate. Joining
  product rows must not repeat a whole transaction's revenue once for every item.
- Separate revenue/refund definitions, units and currency handling. GA4 reporting
  aggregates are not an accounting ledger or automatically attributed order database.
- Prefer additive metric expansion at an existing compatible grain when sufficient.
  Introduce a new recipe only when the question needs different dimensions or semantics.
- Present source quality and per-window coverage with the new measures, not only
  counts of successfully scheduled jobs.

Exit: selected traffic and ecommerce questions reconcile to matching source reports;
source totals stay independent of overlapping breakdown sums. Historical expansion
has an explicit property/window budget and no automatic all-tenant full resync.

## Phase 5 — Exact windows and visible quality

Exact window users require a source report for that window and filter/grain, not a
sum of daily unique counts. Materialize supported common windows and queue bounded
cache misses for requested windows. No live Google request per widget or unrestricted
enumeration of date/filter combinations.

Window-result identity includes property, date bounds, property timezone, scope,
fields, predicates, recipe/contract revision, currency and authorization scope. Never
roll up or merge window-distinct records as daily observations. Refresh policies must
account for source latency and revisions, with pending/stale/partial/unavailable states.

Summarize retained thresholding, sampling, data-loss, missing-weight and currency
metadata into readable report quality indicators across Explorer and widgets. Unknown
historic quality is unknown, not proof of complete data. Keep the existing reported
user-sum metric distinct from the new exact-window metric in saved definitions.

Exit: a repeat visitor across dates counts once in the appropriate source-window
result; overlapping breakdowns do not inflate its total; cache misses remain pending
until complete rather than showing a false zero or reusing an incompatible window.

## Phase 6 — Advanced reports and backend boundaries

Evaluate funnels/cohorts as dedicated report families with their own APIs, definitions,
date windows and reducers. Ordered journeys cannot be reconstructed from independent
event-count marginals. Verify endpoint maturity, permissions and limits before exposing
production controls; do not label them supported based on generic Core metadata.

If required questions fundamentally exceed available aggregated reports, separately
evaluate a warehouse/export connector with its own access, cost, retention and semantic
contract. Do not imply an export can recover history that was never available. This
is a distinct product decision, not a prerequisite for the useful scoped Data API cache.

Exit: each advanced feature has a proven source path and cache model, or a clear
unsupported boundary. The product does not promise all possible marketing analyses.

## Catalog maintenance: required for every phase

- Update the driver-owned definitions and effective tenant catalog in the same change
  as ingestion/reducer support. Version new semantics and retain old saved definitions.
- Update Facade's GA4 runtime catalog, scope metric options, friendly labels and the
  [GA4 reference](../../apis-hub-facade/docs/google-analytics-catalog.md). Migrate toward
  tenant-provided capabilities so Facade does not become a second independent schema.
- Add checks for referenced metric/recipe existence, supported reducers, dimension
  budgets, source/cached/reportable status and multi-property scope compatibility.
- Add coverage to the full tree: property -> reporting purpose -> measurement and
  attribution scope -> recipe/grain -> metrics -> filters -> quality/exactness.
- Update examples and release notes whenever a field becomes available, changes
  meaning or requires backfill. No selectable UI metric without its complete path.

## Scheduling, storage and rollout safeguards

Use per-property/tenant source budgets, quota feedback, bounded concurrency,
coalescing, retry/backoff and fair scheduling. Metadata refreshes, backfills and
advanced reports must not crowd out normal sync. Keep dashboard reads cache-first
and preserve the existing four-widget request concurrency under shared tenant budgets.

Choose a representative high-cardinality property and PostgreSQL copy for each
increment. Record rows, source requests/tokens, storage growth, repair duration and
dashboard latency before choosing rollout thresholds. No unsupported universal ETA.
Retain completed good observations on failed retries and make partial coverage visible.

Release SDK/core -> driver -> tenant/API -> Facade consumers as required, with tenant
capability gates. Use a new release after v1.18.4; do not move the published tag.
Separate schema deployment from lengthy, checkpointed historical jobs. Back up before
data transformation, provide progress/cancellation/resume, validate duplicate policy,
and canary outside sync/peak hours before wider rollout. Registration must not trigger
an implicit global wipe or all-tenant replay.

## First implementation tranche

1. Phase 0 fixtures and event-path coverage/catalog corrections.
2. Phase 1 discovery, conversion and selector support for ordinary/custom/key events.
3. Phase 2 effective property catalog and bounded recipe validation, preserving existing
   scopes and adding joint-query/additivity guarantees.
4. Source reconciliation and PostgreSQL/performance acceptance for those capabilities;
   publish the updated GA4 catalog before starting custom-field expansion.

Implementation is complete per capability only after request -> discovery/conversion
-> persistence -> aggregation -> Explorer/widget/API checks pass at the declared grain.
Planning does not authorize a production replay or imply source parity has been proven.

## Implementation checkpoint — first increment

Implemented eventCount-based paginated discovery for ordinary/custom events, raw
source identities with optional canonical classification, failed-discovery propagation,
and property/channel-safe event persistence. SDK pagination follows absolute row
count and fails on premature empty pages. Existing event conversion and Explorer/widget
breakdowns remain the reporting path; no cache-grain change was introduced.

Default source metrics/dimensions now share declared driver recipes. Sync rejects
unknown recipes and out-of-recipe metrics. A property-metadata recipe projection
separates availability from unchecked compatibility and unknown cache coverage.
The runtime Facade catalog records event semantics and dimension constraints.

Still pending: persisted effective property catalogs and consumer capability exposure,
registered custom-field configuration, authenticated reconciliation, historical link
audit/repair, PostgreSQL/performance acceptance and subsequent phases. Do not mark
Phase 1 fully accepted until source-to-report checks pass on the canary property.
No dependency release, production replay or published-tag change is part of this work.

Offline validation: 36 focused tests pass (driver 12, SDK 5, tenant event/persistence
9, Facade catalog/reporting 10). Broader tenant aggregation: 131/133 pass; two
SocialOrganicStrategy sentinel-account metadata expectations fail in unchanged
code. These results do not replace authenticated GA4/PostgreSQL reconciliation.

## Primary references

- [Dimensions and nine-dimension limit](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/Dimension): also documents expression inputs and delimiter ambiguity.
- [runReport](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport): dates, filters, pagination and report requests.
- [API schema and custom fields](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema).
- [Property metadata](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/getMetadata) and [compatibility checks](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/checkCompatibility).
- [Source quotas](https://developers.google.com/analytics/devguides/reporting/data/v1/quotas).
- [Dedicated funnel reports](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1alpha/properties/runFunnelReport): evaluate separately from Core report capabilities.
