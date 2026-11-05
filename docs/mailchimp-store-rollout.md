# Mailchimp store metrics rollout

Store orders use an order identity scoped to the Mailchimp account and store.
Daily metric configurations include audience, store ID and currency. The driver
publishes these requirements through `identity_dimensions`,
`account_platform_id_field` and `require_source_date` on its preaggregation rule.
The tenant consumes those declarations using the existing dimension tables.

Data Preview has a Connected Stores tab with Orders and Revenue tiles, a daily
chart, a store/currency table and a currency selector. Widget Builder offers
the same metrics in the Connected store orders scope. Audience access restrictions
apply. Revenue sums order totals in their source currency. Mixed-currency revenue
returns null until filtered or grouped by currency. It is not converted to USD.

## Deployment and existing history

1. Release the updated `mailchimp-hub-driver`, update the tenant Composer lock to
   that release, and deploy the tenant and Facade changes together.
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
