# Mailchimp store metrics rollout

Store orders use an order identity scoped to the Mailchimp account and store.
Daily metric configurations include audience, store ID and currency. The driver
publishes these requirements through `identity_dimensions`,
`account_platform_id_field` and `require_source_date` on its preaggregation rule.
The tenant consumes those declarations using the existing dimension tables.

## Commerce expansion contract (2026-11-06)

Launch acceptance targets brand-new projects. Automatic or forced upgrade resyncs are explicitly excluded from this launch scope. The historical repair instructions below remain optional operator tools for old data.

- Source SDK 1.1.0 and driver 1.1.3 contain the initial expansion. Further feature-branch changes must be released and installed before the stricter rules below take effect. Do not reuse an existing release tag.
- The acceptance follow-up also requires the driver's scheduled `entities_sync: entities` rule and `sync(entity=entities)` dispatch. Fresh projects run that entity job before dependent order jobs using the normal instance generator. Audience persistence reads normalized provider account ownership as well as the legacy raw field. Installing only the earlier driver will not populate the complete graph through normal scheduling.
- The worker's 1.20.0 additive upgrade introduces store-to-audience and commerce source relationships plus cart storage. Run the normal tenant upgrade before entity synchronization. The upgrade can be repeated and retains existing metrics.
- Store identity is the SHA-256 hash of JSON `[providerAccount, sourceStore, "store"]`. Order identity is the hash of JSON `[providerAccount, sourceStore, sourceOrder]`. Raw source IDs remain in payloads. Promo-code identities also include the parent rule and source code ID.
- Catalog lists and profiles are gated by physical schema capability and scoped through the persisted store/audience relationship. Variants belong to products, codes belong to promotions, and orders link to verified customers. Order profiles list related products, variants and uniquely matched promo codes. Connected Stores resolves names, domains and Catalog links in bounded metadata batches.
- Product variants and promo codes use their own paged endpoints. Carts require `sync_carts: true` and remain source snapshots. Order/cart line quantities and amounts remain in raw payloads. Catalog exposes scalar line counts and known quantity totals, without exposing customer addresses in commerce payloads.
- A supplied order `lines` or `promos` collection replaces its prior relationship set transactionally. An ambiguous promo string is retained in source data without guessing a code relationship.
- Promo-code snapshots using the earlier unscoped `platform_id` convention are retained for recovery and excluded from new code Catalog reads and order matching. Source replay writes the scoped replacement.

`orders_count` and `revenue` share an eligibility population: verified account/store/order identity, source date, valid currency, and a known numeric source total. A known zero is valid. Missing totals and statuses remain unknown. Revenue is the sum of reported order totals, without a paid-status filter, refund calculation, net-revenue claim or currency conversion. The command reports excluded records.

The driver refreshes all orders for enabled stores to capture historical edits. Its `refresh_all_source_dates` rule also rebuilds cached source dates and previously materialized store dates outside the job window. Moving an order to another day clears the old slice. This deliberately favors correctness over incremental-fetch efficiency. Store rollup failures fail the sync job so its normal retry can repair materialization.

Snapshot deletion is conservative. An absent source row is retained locally, since a partial traversal or access change cannot establish deletion. No tombstones or abandoned-cart metrics are inferred. Verify deletions and incremental watermarks with a controlled source before adding automatic retirement. A source-reported cancellation updates the snapshot but does not invent a net-revenue metric.

Data Preview has a Connected Stores tab with Orders and Revenue tiles, a daily
chart, a store/currency table and a currency selector. Widget Builder offers
the same metrics in the Connected store orders scope. Audience access restrictions
apply. Revenue sums order totals in their source currency. Mixed-currency revenue
returns null until filtered or grouped by currency. It is not converted to USD.

## Deployment and existing history

1. Release SDK, then driver, then worker and Facade in dependency order. Install
   the released driver in the worker Composer lock. Deploy the worker upgrade
   before enabling commerce Catalog consumers in Facade. Run the normal tenant
   upgrade lifecycle. No manual schema edits or global cache wipes are required.
2. Complete a Mailchimp channel resync covering the required history. The driver
   refreshes the orders with audience linkage, source dates, store identity and
   currency. Order IDs that previously collided across stores require this fetch
   because the overwritten data cannot be reconstructed from the metric cache.
3. If refreshed orders already exist in the local cache, rebuild a historical
   range using the application command below. It only processes store metrics.

```bash
docker compose exec -T master php bin/cli.php app:rebuild-mailchimp-stores \
  --start=2026-01-01 --end=2026-11-04
```

Use the required history dates. Each day is replaced within a transaction.
PostgreSQL advisory locks serialize concurrent rebuilds for that source and date.
A write failure rolls back that day. Repeating a completed day replaces its values.
Older untyped metrics remain excluded from store reporting. Campaign engagement
metrics and their identities retain their existing behavior.

Orders without a source date, currency or a resolvable audience are excluded from
the new aggregates. The order date uses `processed_at_foreign`, with a supplied
`created_at` accepted as fallback. Currency uses the order value or the store value.
The new rule also requires the persisted store foreign key, scoped order identity
and known total. Legacy snapshots failing those checks need source replay.

The upgrade does not automatically schedule a historical source job or delete old
ambiguous identities. This is accepted for launch, not an unfinished requirement.
When repairing old data explicitly, enqueue entity sync followed by the required
historical order sync using the normal tenant job lifecycle, then rebuild the
range above. Historical collisions require source data and cannot be recovered
from aggregate values. Automatic retirement is deferred until a canary verifies
the replacement population. A rollback retains added schema and source snapshots.

## Verification

- Driver tests verify store-specific order IDs, persisted audience/currency fields,
  source dates, and repeat conversion stability.
- Tenant SQLite tests verify two stores on one audience, same-currency totals,
  mixed-currency rejection, real Universal SQL store/currency breakdowns, replay,
  changed partitions, zero values, and rollback after an injected write failure.
- Facade tests verify audience scope, store/currency table rows, whole-window chart
  currency handling, and scope-specific Widget Builder options.

PostgreSQL execution and authenticated Mailchimp reconciliation require the
deployment environment. No production replay is performed by these tests.

## Acceptance evidence and remaining release gates

Local acceptance completed in the 2026-11-06 follow-up:

- Installed source SDK plus the current driver checkout run against a new Doctrine schema with SQLite foreign keys enabled. Actual source converters, the driver's entity/metric dispatch and worker processors populate two stores with overlapping provider IDs, products, variants, customers, promotions, codes, carts and orders. Disabled audiences are excluded. Repeated syncs preserve row counts and older date/amount edits replace the correct daily aggregates.
- A 20,000-order synthetic cache rebuild runs twice without duplicating configurations or observations. It verifies exact order counts and EUR totals. The focused suite including Catalog and instance generation passed 24 tests / 334 assertions at this volume. This is local SQLite scale evidence, not PostgreSQL production sizing.
- Browser fixtures render the real Livewire/Blade Preview, store list and store profile at 1440px and 390px, in light and dark mode. All four browser scenarios pass. They exercise Alpine requests, currency filtering, Catalog links, related order links and screen-width containment. Synthetic tenant API responses are used. Screenshots are saved under Facade `test-results/`.
- The checks fixed audience ownership, scheduled entity dispatch, mobile Preview control wrapping and profile ID wrapping. Commerce profiles point to Connected Stores instead of claiming a missing daily-performance window.

To validate a coordinated unreleased driver without changing Composer or vendor files:

```powershell
$env:MAILCHIMP_TEST_DRIVER_SOURCE = 'D:\laragon\www\mailchimp-hub-driver'
$env:MAILCHIMP_VOLUME_ORDERS = '20000'
php vendor/bin/phpunit --bootstrap tests/bootstrap-mailchimp-commerce.php --filter='MailchimpCommercePersistenceTest|CatalogReadServiceTest|MailchimpStoreRollupTest|InstanceGeneratorServiceTest|InstanceGeneratorCacheAllTest'
```

After installing the released driver, the source override is unnecessary. The fresh-project test explicitly rejects a driver lacking scheduled entity sync. The local source override is a test bootstrap only and does not change production dependency resolution.

Local checks cover source paging/cancellation, driver callback types and audience
selection, converter graph identities, repeated entity writes, store ownership,
customer audience isolation, promotion ambiguity/removal, mixed currency,
transaction rollback, historical date correction, and Facade Preview/Catalog.
These use mocks and SQLite. The broader aggregation suite currently reports two
failures in `SocialOrganicStrategyTest` sentinel-account telemetry expectations.
Those tests do not execute store materialization. Record their baseline separately
before treating the full regression suite as green.

The operator requested local validation only. PostgreSQL concurrency/production-volume
checks and an authenticated Mailchimp canary remain unverified deployment checks.
Desktop/mobile and light/dark checks are completed above. Provider history, optional fields, access errors,
rate-limit behavior and source deletion completeness are not established by local
fixtures. General entity measures, product/cart/promotion performance, accounting
net revenue, refunds, campaign attribution and FX conversion remain separate work.

### Audience prerequisite during order sync

The driver must persist authoritative enabled ecommerce audiences before importing stores in metric jobs. This removes the scheduling dependency on the separate entity job and fixes `Store audience must be synchronized before its store` for missing parents. Deploy the updated Mailchimp driver before retrying affected jobs; no schema change or forced historical resync is required. Worker ownership checks remain enforced. Local regression covers a metric job running against an empty cache before entity sync.
