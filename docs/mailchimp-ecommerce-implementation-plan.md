# Mailchimp ecommerce implementation plan

## Objective and current state

Make Mailchimp's connected stores and their related ecommerce entities usable throughout APIs Hub, from synchronization through aggregation, Data Preview, the Catalog, and custom widgets. Email engagement and ecommerce orders have different sources and grains. Maintain a complete, versioned channel metrics catalog as each capability is delivered.

The current workspace contains pending changes for store-scoped order IDs, audience/store/currency metric identities, a store rollup and rebuild command, and a Connected Stores preview and widget scope. These changes have not been established as deployed or verified against a live tenant. Review them as the starting point, including their interaction with the work below. The existing store-discovery callback currently lacks a confirmed persistence path to `ChanneledStore`, and `channeled_orders.channeled_store_id` is not populated by the current order processor.

Mailchimp places customers, products, carts, orders, and promo rules under a store. Product variants, order and cart lines, and promo codes belong to those parent entities. Each store has an associated audience, and an audience may have several stores. Availability depends on the connected store and the data it provides. [Mailchimp ecommerce model](https://mailchimp.com/developer/marketing/concepts/e-commerce/)

## 1. Establish and verify the source contract

- Inventory the SDK, driver, tenant processors, entity schema, analytics catalog, and Facade consumers. Mark each entity and field as available from Mailchimp, fetched, persisted, queryable, or missing. Reconcile this inventory with `docs/mailchimp-driver-implementation-plan.md`, `docs/mailchimp-store-rollout.md`, and the Facade Mailchimp analytics catalog.
- Verify store, order, customer, product, variant, cart, promo-rule, and promo-code endpoints and pagination in the current API. Identify SDK gaps and add only the methods needed for the stages below. Document permissions, rate limits, source update and deletion behavior, historical availability, and optional fields with primary documentation and controlled provider checks.
- Verify the actual converter and processor callback contracts in the installed dependency versions. Distinguish provider IDs from APIs Hub IDs and confirm which normalized fields are persisted, including raw payload fields.
- Define a field matrix for store metadata, order dates, amounts, currency, status, customer and campaign references, line items, products and discounts. Treat a missing value as unknown. Record whether values are snapshots, flows, or source-reported totals.

**Exit criteria:** an evidence-backed source-to-consumer inventory and a prioritized set of supported entities and metrics. Do not assume an endpoint's existence establishes complete coverage in a particular account.

## 2. Persist stores and link orders

- Make store discovery dispatch to a supported persistence path. Reuse `Store`, `ChanneledStore`, their existing repositories and identity conventions. Persist the provider ID, name, domain, platform, currency, linked audience, source timestamps, and available attributes without inventing values.
- Resolve the store's `list_id` to the appropriate Mailchimp audience. Enforce channel and account scoping when resolving identifiers. Define behavior for missing audiences, disconnected stores, renames, and multiple stores linked to one audience.
- Populate the existing `channeled_orders.channeled_store_id` relationship on insert and update. Retain source identifiers in order data for recovery and audit. Ensure order identity is unique across provider accounts and stores, and is stable across retries.
- Verify entity and order synchronization ordering and retry behavior. If order data arrives before its store or audience, queue or reconcile the relationship without losing the order. Preserve unrelated channels and existing consumers of the order processor.
- Expose store entities and their scalar attributes through the established tenant API and entity attribute filtering facilities where the underlying relationships support them. Keep authorization tied to the linked audience and project.

**Exit criteria:** stores appear as real tenant entities, orders resolve to the correct store and audience, and repeated and concurrent syncs are idempotent. Tests include identical order IDs in different stores, two stores on one audience, missing relationships, and late entity discovery.

## 3. Reconcile existing data and release safely

- Audit existing order and metric identities and determine which cached records retain enough information to recover a store and audience. Previously overwritten orders cannot be reconstructed from ambiguous aggregates alone.
- Design a tenant upgrade routine using the existing upgrade framework. Stage and validate relationship and identity changes, reconcile duplicate configurations and observations according to the established collision policy, and make the operation restartable and observable. Avoid global wipes and direct manual schema changes. Add Doctrine migrations only if existing relationships and fields prove insufficient.
- Rebuild only the affected Mailchimp order metrics from complete, correctly linked cached orders. Make daily replacement atomic, retry-safe, and safe against concurrent rollups. Exclude obsolete ambiguous aggregates from queries, and retire them through the upgrade routine once verification permits.
- Where source records are incomplete or were overwritten, schedule a targeted Mailchimp historical resync and rebuild for the affected accounts and range. Integrate scheduling, upgrade progress, and long-running advisories with the existing tenant and Facade lifecycle. State explicitly which historical range is recoverable.
- Define release order for SDK, driver, tenant, and Facade, including version pins, upgrade registration, deployment readiness, failure recovery, and rollback boundaries. Validate production-sized PostgreSQL behavior and run a controlled tenant canary before broad rollout.

**Exit criteria:** upgrading or retrying does not double-count orders, affect email engagement, or leave the tenant unavailable. Document the exact treatment of already deployed and unreleased pending changes.

## 4. Complete the ecommerce entity graph

Implement incrementally after store and order identity is stable.

1. **Customers and orders.** Cache store-scoped customers and link orders to both customer and store. Preserve the relationship to the audience while respecting asset access. Keep order status and timestamps available for interpretation.
2. **Products, variants, and order lines.** Cache products and variants as entities, map order lines to the correct store-scoped product and variant, and preserve quantity and source amounts. Verify identifiers that can overlap between stores and missing/deleted products.
3. **Promotions.** Cache promo rules and codes using their store and parent identities. Link order-level promotions only when the source supplies an unambiguous association.
4. **Carts and lines.** Add carts and their lines as a separate lifecycle scope if source completeness and update semantics are verified. Establish abandoned-cart definitions before deriving performance metrics.

For each increment, reuse existing normalized entities and processors where their contracts fit. Add or extend schema through the normal migration system only after identifying a missing relationship. Provide bounded pagination, resumable jobs, rate-limit handling, and explicit handling of partial provider failures.

**Exit criteria:** every exposed relationship can be queried through normal tenant entry points, survives retries and source updates, and has coverage recorded in the catalog. Do not present source-only entities as cached capabilities.

## 5. Metrics, scopes, and aggregation contracts

- Maintain independent catalog scopes for campaign engagement, store orders, order lines/products, and carts/promotions when implemented. Declare source endpoint, entity grain, dimensions, attribution, supported combinations, null behavior, units, and reducers for every metric.
- Start with order count and the sum of source order totals by day, audience, store, and currency. Define status and date policies before adding net revenue, refunds, taxes, shipping, average order value, customer counts, product performance, or promotion performance. Do not label an order-total sum as accounting net revenue.
- Preserve store and currency identities in the query contract and make the store relationship available to the aggregation engine. Support entity attribute filters through the existing metadata-driven mechanism. Do not duplicate an aggregate at both store and rollup grain without an explicit scope discriminator.
- Sum monetary values only for a single currency or after an explicit, documented conversion. For ratios, calculate from matching numerator and denominator at the requested grain. Distinct customers and carts are non-additive across overlapping periods or stores unless derived from identities at the requested window.
- Use the optimized Universal SQL execution path and aggregation profiles for supported query shapes. Validate planner routing and performance across all affected channels. Report unavailable or incomplete coverage as such rather than manufacturing zeroes.
- Treat campaign attribution as a separate capability. Use order campaign references only after verifying the source's attribution semantics and coverage. Do not infer a campaign relationship from a generic time window.

**Exit criteria:** the catalog, tenant query contract, Data Preview, and widget options agree on each supported metric, scope, breakdown, filter, and reducer. Tests compare aggregated results against source records, including duplicate, partial, multi-currency, and overlapping cases.

## 6. Facade and Catalog consumption

- Update Connected Stores in Data Preview to use the cached store entity. Show its name, domain and ID, the linked audience, orders and source-currency revenue. Keep the existing tiles, daily chart, store/currency table and currency selector coherent with tenant responses and empty/error states.
- Add corresponding store, order, customer, product, variant, cart, and promotion list/detail views to the Catalog implementation as each entity becomes available. Surface relationships and data coverage, and integrate Asset Groups only where the entity model and access rules support them.
- Make custom widget metrics, scopes, breakdowns, entity attributes and filters follow the same channel catalog and tenant capabilities. Restrict unsupported combinations at both selection and request validation. Preserve existing saved widgets and explicitly handle unavailable legacy data.
- Apply project membership, audience and asset-group access to store entities, order records, aggregation requests, widgets and public dashboards. Test users with restricted assets and stores sharing an audience.

**Exit criteria:** a user can discover a store by name, inspect its relationships, preview supported metrics, and build a widget with the same results and access scope. Verify desktop, mobile and light/dark rendering.

## 7. Acceptance and rollout gates

- Unit and integration tests cover conversion, entity dispatch, relationship upserts, pagination, replay, currency guards, reducers, error propagation, and catalog consistency. Exercise actual tenant API and Universal SQL entry points, not only isolated formulas.
- PostgreSQL tests cover transactions, locks, interrupted rebuilds, concurrency, duplicate reconciliation, and representative data volume. Run regression suites for existing Mailchimp email data and unaffected channels.
- Facade tests cover Preview requests and rendering, metric and breakdown selectors, widgets, mixed currencies, asset authorization, stale browser caches, and missing observations. Validate persisted data and displayed values against controlled Mailchimp accounts and source responses.
- Release each stage only after its migration/backfill, dependency versions, catalog, Preview, widgets, and tests are ready together. Record remaining gaps and provider-dependent availability in the final catalog.

## Decisions to resolve during the source audit

- Whether store entities should become selectable Asset Group members or remain children of audience assets under existing access rules.
- Which order statuses and source dates define each business metric, and how refunds or cancellations revise historical periods.
- Which cart and promotion use cases justify synchronization, given source availability and rate limits.
- Whether cross-currency reporting needs an exchange-rate service and an explicit conversion-date policy.
