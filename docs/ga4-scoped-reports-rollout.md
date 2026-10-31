# GA4 scoped reports: deployment and acceptance

## Behavior and cache boundaries

The driver owns source fields, units, scope, recipes and compatibility. Property
metadata is persisted in the tenant report cache (24-hour freshness). Explicit
catalog refresh is allowed after five minutes. Catalog availability is distinct
from scheduled selection, cached windows and compatible reportability. Completed
window coverage is authorization-specific; it does not imply arbitrary combinations
are present. Missing values never become measured zero.

Legacy daily recipes keep their identities and defaults. New opt-in scopes are:

| Scope | Measures | Grain beyond property/date |
|---|---|---|
| engagement_matrix | sessions, engagedSessions, userEngagementDuration, engagementRate | session channel, source/medium, landing page, device, country |
| ecommerce_matrix | ecommercePurchases, transactions, purchaseRevenue, grossPurchaseRevenue, refundAmount | session channel, source/medium, session campaign, device, country |
| product_matrix | itemsViewed, itemsAddedToCart, itemsPurchased, itemsRefunded, itemRevenue, grossItemRevenue, itemRefundAmount | item ID, name, brand, category, variant |

Each new scope carries `recipe_revision=1`. Item reports exclude transaction totals;
never join transaction revenue onto every item. Purchase revenue and total revenue
are separate measures; currency guards reject unknown/mixed currencies in cached
aggregation. Rates retain complete-grain session weights within calendar and bounded
end-anchored trailing buckets. The Facade does not sum the resulting bucket averages.

Registered custom fields are explicitly chosen from the property metadata in Data
Explorer's Property reports workspace. Custom sums/counts and averages retain distinct
source definitions. Arbitrary selected reports are cached as whole source responses,
including source totals, and never rolled up from incompatible daily caches.

Whole-window users have no date dimension. Breakdown memberships may overlap: source
totals are displayed separately, never reconstructed by adding breakdown rows. These
reports can be saved to dashboard tables or metric cards without breakdowns; public
views reuse the saved widget's authorization context. Field definitions, timezone or
currency drift requires a newly reviewed saved definition. Daily user widgets retain
their existing heading tooltip because their figures are reported daily sums.

Cohorts use firstSessionDate/cohortSpec and cohort measures, not ordinary dateRanges.
Funnels use the dedicated v1alpha endpoint and remain disabled until explicitly
validated and enabled per tenant. They are not synthesized from independent event
counts. Arbitrary journeys, user-level joins, unavailable history and warehouse
exports require a separate connector; they cannot be reconstructed from this cache.

## Deployment order

1. Release the SDK with the alpha funnel method and the Google driver with catalog
   compilation/new recipes. Publish and pin those versions in the tenant before
   enabling the Facade UI. No version numbers are allocated by this source change.
2. Deploy the tenant generic scoped-report endpoints, store and worker. Preserve
   `storage/analytics-reports` on a persistent volume shared by the master HTTP and
   cron processes. It contains private source data; directory/file permissions are
   0700/0600. Do not expose it through the web server or run separate replicas with
   unshared local stores.
3. Regenerate master cron (`bin/setup-cron.php` through normal deployment). It runs
   `app:process-scoped-reports --limit=3` each minute. Ensure the CLI and HTTP user can
   read/write the same cache. Recreated cron is required; an existing master without
   this entry will leave reports queued.
4. Deploy Facade assets and regenerate its release configuration schema so Data
   Sources includes the optional GA4 recipe selections. Enable only needed scopes.
5. Schedule history for newly enabled scopes during low activity using existing
   selective channel/date scheduling. Do not wipe all GA4 metrics for these additive
   scopes. They begin empty until synced. Inspect historical identity/weight audits
   before repairing legacy data; replay over inconsistent old identities is unsafe.

## Budgets, retries and operational limits

HTTP reads never call Google. Per-tenant pending budget is 100, per-actor 10; completed
fresh definitions coalesce. The worker handles at most one job/property/run, with
cross-process property locks and ten source jobs/minute (at most two calls each,
SDK retries disabled). Catalog work uses metadata plus a tiny context probe; reports
use compatibility plus a report. Quota feedback introduces property cooldowns; source
failures cool down the property and failed definitions retry no sooner than five
minutes. Existing sync/API budgets remain in force; this queue does not increase the
normal four-widget browser concurrency.

Definitions include property, explicit dates, timezone, currency, authorization,
fields, predicates, family, contract and revision. Maximum nine dimensions (including
date), ten metrics, 366-day ordinary windows, 10,000 source rows, 8 MiB result and
1,000 retained cache records. Cohort follow-up is separately bounded. Oversized or
partial responses fail rather than become apparently complete reports. Empty complete
responses are valid. Catalog TTL is 24 hours; reports are fresh for one hour. A failed
refresh retains last-good data as explicitly stale, not ready. Abandoned processing
leases recover after five minutes; source reads are idempotent and late claim results
cannot overwrite newer ones. Expired report records older than a day can be evicted
when capacity is reached. The UI stops automatic polling after six checks; manually
checking the same definition coalesces rather than launching duplicate requests.

## Release acceptance

Offline regression coverage verifies dimensions/identity/auth isolation, custom-field
semantics, item/transaction separation, partial response rejection, cancellation before
source reads, queue fairness/coalescing/staleness/quotas, weighted temporal results and
Explorer-to-widget definition preservation. It does not establish Google parity.

Before production-wide enablement, use one canary property with session, user, event,
custom and ecommerce fixtures. Compare source totals for one day and a multi-day
window; verify timezone boundaries, ordinary events with zero key events, refunds,
item revenue and currency, registration changes, thresholding/sampling, permissions
revoked after enqueue, crash/restart lease recovery, and concurrent ordinary syncing.
Measure PostgreSQL plans and volume/latency on a realistic history. Enable alpha
funnels only after comparing ordered source funnel results. Keep default legacy
recipes active and new optional scopes disabled if a canary fails. No source parity,
Linux crash recovery or PostgreSQL volume claim is made by the local unit suite.
