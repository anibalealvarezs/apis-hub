# Agnostic Pre-Aggregation Engine Architecture Specification

## 1. Architectural Purpose & Problem Statement

APIs Hub features a dual ingestion model:
1. **Time-Series Streams (Daily Metrics):** Fixed minimal 1-day granularity ingested directly into the metric store (`metric_configs`, `metric_values`).
2. **Atomic Entity Streams:** Discrete objects and event logs (eCommerce orders, line items, email activity logs, web sessions) captured with granular timestamps ($T$), monetary totals, and recipient identifiers.

The **APIs Hub Current Aggregation Engine** (`AggregationExecutor`, `AggregationPlanner`) is designed for fast OLAP roll-ups across 1-day minimal granularity. Querying millions of raw atomic rows in real-time across complex multi-channel dashboards would degrade performance.

The **Agnostic Pre-Aggregation Engine** bridges this gap by acting as an asynchronous, idempotent batch processing pipeline that rolls raw atomic entities into 1-day canonical metric slices before the analytical queries hit the database.

---

## 2. Ingestion & Pre-Aggregation Pipeline

```
┌────────────────────────────────────────────────────────────────────────┐
│                   Channel Sync Worker Execution                        │
│   (e.g., Mailchimp, Shopify, WooCommerce, Klaviyo, Stripe, etc.)       │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
                    [Universal Entity Ingestion]
             ChanneledOrder, ChanneledCustomer, ChanneledStore,
                     EmailActivityEvent, WebSession
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      Atomic Persistence Layer                          │
│     - DBAL transactional batch persistence                             │
│     - Raw timestamps, monetary figures, and MD5 identity hashes        │
│     - State tracking: pre_aggregated_at IS NULL                        │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Agnostic Pre-Aggregation Engine                      │
│             (Triggered at Sync Tail or via Scheduled Worker)           │
├────────────────────────────────────────────────────────────────────────┤
│  Executes Driver-Defined Pre-Aggregation Contracts:                    │
│                                                                        │
│  1. Universal Date Partitioning:                                       │
│     - Slices atomic event logs strictly into YYYY-MM-DD grains         │
│  2. Driver Contract Execution (`PreAggregationProviderInterface`):     │
│     - Driver declares metric derivation formulas over atomic payloads  │
│     - Driver defines its own metric keys (e.g. opens, clicks, revenue) │
│     - Driver does NOT know APIs Hub database tables or schema          │
│     - Engine does NOT know provider-specific business logic            │
│  3. Universal Cross-Channel Attribution Engine:                        │
│     - Evaluates algebraic identity matching (identity_hash, windows)   │
│     - Links raw events to Canonical Entities (Campaign, Store, Page)   │
│  4. Canonical Ingestion:                                               │
│     - Persists normalized daily metric values into the metric store    │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Canonical Agnostic Metric Store                      │
│       (metric_configs, metric_values [1-day minimal grain])            │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│                     APIs Hub Aggregation Engine                        │
│   Fast, real-time OLAP queries across dates, accounts, and channels    │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Strict Channel-Agnostic Design Principles

1. **Drivers Own Metric Definitions, APIs Hub Owns Execution:**
   - **Drivers:** Define which metrics they produce, how those metrics are computed from their atomic data payloads, and how they map to canonical concepts. The driver never references internal APIs Hub DBAL/ORM tables or schema details.
   - **APIs Hub Pre-Aggregation Engine:** Operates as a generic execution pipeline. It consumes driver contracts (`PreAggregationProviderInterface`), applies the driver's declared reduction rules over the atomic payload stream, and persists the resulting daily metrics.
2. **Identical Contract Pattern to Active Channels:**
   - In active channels (GSC, GA4, Meta Marketing, Meta Organic), drivers expose:
     - `AggregationProfileProviderInterface`: Declares aggregation profiles, valid dimensions, and reducer strategies (`sum`, `weighted_by_metric`).
     - `CanonicalMetricDictionaryProviderInterface`: Maps canonical keys to raw platform metric names.
   - Atomic pre-aggregation follows this exact precedent:
     - A new `PreAggregationProviderInterface` in `api-driver-core`.
     - The driver provides the mapping of raw atomic fields $\to$ daily metric keys $\to$ reduction functions (`sum`, `count`, `count_distinct`).
     - New metrics can be added by any driver at any time without altering a single line of the APIs Hub Pre-Aggregation Engine code.
3. **Arbitrary Minimal Granularity (1-Day Grain):**
   All aggregated output emitted by the engine conforms strictly to APIs Hub's universal 1-day grain (`YYYY-MM-DD`).
4. **Idempotence & Reprocessing:**
   The engine can be re-executed over any date range across any tenant cluster (`app:pre-aggregate-daily --from=YYYY-MM-DD --to=YYYY-MM-DD`). If a driver updates its metric definitions or attribution formulas, daily values recalculate deterministically without touching external third-party APIs.
5. **Agnostic Attribution Algebra:**
   Attribution rules operate on conformed identity hashes and timestamp windows, independent of channel identity:
   $$\text{Attributed}(E, O) \iff \text{event}.identity\_hash = \text{order}.identity\_hash \land 0 \le (T_O - T_E) \le \Delta T_{window}$$


