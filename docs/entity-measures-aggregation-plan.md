# Entity Measures Aggregation & Facade Integration Master Plan

**Status** · High-Level Product & Architectural Synthesis + Technical Audit Complete  
**Delivery Track** · Worker Unaggregated Entity Aggregation Engine (`apis-hub`), SDK Client (`apis-hub-api`), and Deep Facade Integration (`apis-hub-facade`) across DataPreview, Entity Pages, and Custom Dashboards.

---

## 1. High-Level Product & Architectural Synthesis (Does This Plan Make Sense?)

Before touching implementation details, we must verify that the architecture directly serves APIs Hub's core mission and product workflow.

### 1.1 Why Pre-Aggregated `metrics` Alone Are Not Enough
APIs Hub ingests two fundamentally different kinds of data from external platforms:
1. **Provider-Aggregated Time Series (`metrics`)**: Daily counters reported by ad/analytics platforms (impressions, clicks, spend, sessions). These have no row-level identity—you cannot ask *"which customer clicked this ad and later bought 3 times on Shopify?"* from `metrics`.
2. **Unaggregated Relational Entities (`orders`, `customers`, `products`, `stores`, `campaigns`, `discounts`, `events`)**: Individual records with timestamps, statuses, line items, and canonical cross-channel identities (`orders.id` via `order_id`, `customers.id` via `email`, `products.id` via `sku`/`product_id`).

All of the target business questions we want to answer—**AOV, Order Velocity, Repeat Purchase Cohorts ($>3$ orders/year), Pre-Purchase Multi-Channel Touches, Cross-Store Customer LTV, Discount Impact, and Cross-Channel Order/Customer Reconciliation**—are fundamentally **relational, cross-entity, and cross-channel questions** over these unaggregated entity tables.

---

### 1.2 The Unifying Conceptual Model: Two-Stage Aggregation & Dual Grain

A critical high-level realization is that **Entity Detail summaries**, **Entity List summaries**, **DataPreview summaries**, and **Custom Dashboard Entity Measures** are not four disconnected features. They are governed by a single **Two-Stage Aggregation Model** combined with **Canonical vs. Channel Grain**:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ STAGE 0: Cross-Channel Canonical Identity Resolution                         │
│ • Canonical Customer (customers.id) ← Mailchimp + Klaviyo + Shopify + Leads  │
│ • Canonical Order (orders.id)       ← Shopify + Mailchimp + GA4 + Google Ads │
│ • Canonical Product (products.id)   ← Store A SKU + Store B SKU              │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│ STAGE 1: Per-Entity Relational Rollup (Powers ENTITY DETAIL PAGES)           │
│ Computes cross-channel facts for ONE canonical entity instance:              │
│ • Customer(id=X): Total orders & LTV across stores, 1st order date,          │
│                   pre-purchase touches by channel (Mailchimp, Klaviyo, etc.) │
│ • Order(id=Y):    Order total, discount, items, and cross-channel presence   │
│                   matrix (which channels reference this order & values)      │
│ • Store(id=Z):    Store revenue, order count, AOV, repeat buyer rate         │
│ • Product(id=P):  Orders containing product, units sold, line revenue        │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│ STAGE 2: Population Aggregation over Stage 1 (Powers LISTS, PREVIEW, WIDGETS)│
│ Filters, buckets, or groups Stage 1 across an authorized entity population:  │
│ • Cohort Filter:  WHERE customer.orders_in_window >= 3                       │
│ • Scalar Summary: AVG(customer.revenue), AVG(customer.pre_purchase_touches), │
│                   SUM(order.revenue) / COUNT(DISTINCT canonical_order_id)    │
│ • Time-Series:    GROUP BY day / week / month / year                         │
│ • Comparison:     GROUP BY channel / store / status / product                │
└──────────────────────────────────────────────────────────────────────────────┘
```

#### Why This Two-Stage Model Solves Everything Cleanly:
1. **No Contradictory Logic Between Detail Pages and List/Dashboard Pages**:
   - On a **Customer Detail Page**, we display **Stage 1** for `customer_id = X` (their cross-store total purchases and their pre-purchase touches broken down by Mailchimp, Klaviyo, Meta Leads).
   - On the **Customers List Page** (or a Custom Dashboard widget), we run **Stage 2** over those exact Stage 1 customer rollups (`AVG(customer_revenue)` across channels, `% of customers with >1 or >3 orders`, `AVG(pre_purchase_touches)`).
2. **Solves the Cross-Channel Double-Counting vs. Cross-Store Summing Dilemma**:
   - **Same Order Referenced in Multiple Channels** (e.g., Order `#1001` synced from Shopify, mirrored in Mailchimp Ecommerce, and tracked in GA4):
     - In **Unified/Aggregated Mode**, Stage 1 collapses by canonical `orders.id` (`COUNT(DISTINCT o.order_id)`) so Order `#1001` is counted **once** and its revenue is counted **once**.
     - In **Channel Comparison Mode** (`dimensions: ['channel']`), Stage 2 groups by channel so the user can compare Shopify vs. Mailchimp vs. GA4 side by side.
   - **Different Orders Placed by the Same Customer Across Multiple Stores** (e.g., Customer `alice@example.com` buys Order `#1001` on Store A and Order `#2005` on Store B):
     - Because `#1001` and `#2005` have distinct canonical `orders.id` linked to the same canonical `customers.id`, Stage 1 sums both orders into Alice's true **Cross-Store Total Purchases & LTV**!

---

### 1.3 How the Three Facade Contexts Fit Together as a Cohesive Product

When viewed at a high level, the three Facade integration points form a natural progression from **Cache Verification $\rightarrow$ Entity Exploration $\rightarrow$ Executive Blending**:

| Facade Context | Primary Orientation | Scope & Grain | Core Purpose for the User |
| :--- | :--- | :--- | :--- |
| **1. DataPreview Pages** *(Context C)* | **Channel-First** *(Single-Channel Cache Audit)* | Single selected channel & asset (`Mailchimp`, `Meta Ads`, `Meta Organic`, `Google Ads`, `GA4`, `GSC`) | **Confidence in Cached Data**: Shows the user that raw entities for that channel are synced and previews the contextual entity measures (Store AOV, Audience status mix, Promo redemptions, Pre-purchase touches, Campaign budgets, Post mix) derived from that cache. |
| **2. Entity Pages (`CatalogEntity`)** *(Context B)* | **Entity-First** *(Cross-Channel Exploration & 360° Inspection)* | Cross-channel canonical entities (`Orders`, `Customers`, `Stores`, `Products`, `Campaigns`, `Promo Codes`) | **Bespoke List vs. Detail Intelligence**:<br>• **List Page (Stage 2)**: Population summaries & cross-channel comparisons (e.g. Orders AOV, Customers avg cross-channel revenue, repeat cohorts).<br>• **Detail Page (Stage 1)**: Single-record cross-channel 360° profile (e.g. Customer's multi-store purchases & multi-channel touches; Single Order's cross-channel presence). |
| **3. Custom Dashboards (`JointDashboard`)** *(Context A)* | **Goal-First** *(Cross-Source Blending, Formulas & KPIs)* | Arbitrary user-selected widgets, series, Derived Metrics, and Statistical KPIs | **Unified Executive Analytics (4th Data Source)**: Treats Entity Measures as a Level-1 peer to Standard Metrics—returning Scalars, Time-Series, or Breakdowns that plot on the same charts, combine with Ad Spend in **Derived Metrics** (Blended ROAS, CAC, POAS), and feed **Statistical KPIs**. |

---

### 1.4 Why Entity Measures as a "Level-1 Peer to Standard Metrics" Is the Right Dashboard Choice

Earlier we debated whether to force entity aggregations into the `metrics` table or introduce them as a new source. The high-level answer is:
- **Separate Computation Path, Unified Output Contract**:
  - **Computation Path**: Pre-aggregating every possible entity cohort, product filter, cross-channel deduplication, and multi-touch window into the `metrics` table is impossible without combinatorial explosion. Entity Measures must be computed dynamically over `0B` (entity tables) via `/api/catalog/aggregate`.
  - **Output Contract**: Once computed, an Entity Measure returns the **exact same data shapes** as a Standard Metric query:
    1. **Scalar** (`{value, previous_value, delta}`)
    2. **Dated Time-Series** (`[{date: 'YYYY-MM-DD', value}, ...]`)
    3. **Dimensional Breakdown** (`[{dimension, value}, ...]`)
  - Because the output shapes are identical at Level 1, **Derived Metrics** (`FormulaEvaluator`) and **Statistical KPIs** (`KpiPayloadBuilder` / Analytics Engine) can consume Entity Measures alongside Standard Metrics with zero architectural friction.

---

## 2. Unified Analytics Hierarchy & Output Data Shapes

### 2.1 Four-Source Hierarchy
```
Level 0: Cached Tenant Data
├── 0A. Pre-Aggregated Time Series (metrics, metric_configs)
└── 0B. Unaggregated Entity Tables (orders, channeled_orders, customers, channeled_customers,
        products, channeled_products, stores, channeled_stores, channeled_campaigns,
        channeled_ad_groups, channeled_ads, posts, channeled_discounts, channeled_events)

Level 1: Base Analytical Sources (Isomorphic Outputs: Scalars, Time-Series, Breakdowns)
├── Source 1: Standard Metrics (from 0A via /{channel}/metrics/aggregate)
└── Source 2: Entity Measures (from 0B via /api/catalog/aggregate)

Level 2: Composed Formulas
└── Source 3: Derived Metrics — Algebraic AST f(Standard Metric, Entity Measure)
    ├── Blended ROAS = Entity(orders.revenue) / (Metric(facebook_marketing.spend) + Metric(google_ads.spend))
    ├── CAC = Total_Ad_Spend / Entity(customers.customer_count)
    └── Net Margin Proxy = (Entity(orders.revenue) - Entity(orders.total_discount) - Entity(orders.total_tax)) - Total_Ad_Spend

Level 3: Statistical Intelligence
└── Source 4: Statistical KPIs — Trend, PoP delta, R² correlation, and target health over Level 1 & Level 2
```

### 2.2 Three Output Data Shapes from `/api/catalog/aggregate`
1. **Scalar Summary (`time_bucket = null`, `dimensions = []`)**:
   - Returns a single summary row (`order_count`, `canonical_order_count`, `revenue`, `aov`, `total_discount`, etc.) plus `meta.currency` and `meta.currency_mixed`.
   - Consumed by: Dashboard Stat Cards, Entity List Summary Strips, Entity Detail 360° Panels, Scalar Derived Metrics.
2. **Time-Series (`time_bucket = 'day' | 'week' | 'month' | 'year'`)**:
   - Returns chronologically ordered rows keyed by `time_bucket`.
   - Consumed by: Line/Area/Bar trend widgets, Multi-Series charts (overlaying Standard Metrics + Entity Measures), Time-Series Derived Metrics, Statistical KPIs.
3. **Categorical / Dimensional Breakdown (`dimensions = ['channel' | 'store' | 'financial_status' | 'fulfillment_status' | 'currency' | 'product' | 'status' | 'media_type' | 'touch_channel' | 'frequency_bucket']`)**:
   - Returns grouped rows for cross-channel comparisons, store breakdowns, and status distributions.
   - Consumed by: Cross-channel comparison tables, Donut/Pie charts, Horizontal bar charts, Entity List distribution pills, Customer 360 touchpoint breakdowns.

---

## 3. Technical Guardrails from Codebase Audit (`apis-hub`)

1. **`channel` Column is an Integer FK (`channels.id`)**:
   - In all `channeled_*` tables, `channel` is an `INT` FK to `channels(id)`. Every query filtering or grouping by `channel` MUST `INNER JOIN channels ch ON ch.id = e.channel` and select/bind `LOWER(ch.name)`.
2. **Polymorphic Customer Ownership (`customerSourcePredicate`)**:
   - `channeled_customers` rows are scoped via Audience/Page account (`cc.channeled_account_id`), Commerce Store (`cc.channeled_store_id -> channeled_stores.channeled_account_id`), or Order participation (`channeled_orders.channeled_customer_id`).
3. **Customer-Specific Multi-Touch Attribution (`getPrePurchaseTouches`)**:
   - Events in `channeled_events` and lead submissions in `channeled_customers.lead_data` must be matched to the canonical customer's identity (`customers.email` / MD5 `email_id` / `channeled_customers.id`) where `touch_occurred_at < customer_first_order_at`, supporting both Stage-1 single-customer channel breakdowns (`filters.customer_id` + `dimensions: ['touch_channel']`) and Stage-2 population averages.
4. **1-to-Many Join Fan-Out Prevention**:
   - Filtering `channeled_orders` by `product_id` or `discount_code` uses `EXISTS (...)` subqueries on `channeled_order_channeled_products` / `channeled_order_channeled_discounts` so multi-line orders never multiply order-header revenue or AOV.
5. **Multi-Currency Safety Guard**:
   - Monetary sums/averages (`revenue`, `subtotal`, `total_tax`, `total_discount`, `aov`, `customer_ltv`, `budget_sum`) check `COUNT(DISTINCT currency_code)`. If multiple currencies exist without a `currency` filter or `currency` dimension, monetary fields return `null` with `meta.currency_mixed = true` and `meta.currencies = [...]` while non-monetary counts/rates remain intact.
6. **Canonical Deduplication vs. Channel Comparison**:
   - When aggregating across channels without `dimensions: ['channel']`, canonical measures deduplicate mirrored orders via `orders.id` (`o.order_id`) and canonical customers via `customers.id` (`cc.customer_id`).

---

## 4. Complete Entity Measure Catalog (`EntityMeasureCatalog`)

| Entity Type (`entity_type`) | Primary Tables | Supported Measures | Supported Dimensions | Supported Filters | Time Bucket Column |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`orders`** | `channeled_orders` `o`<br>`LEFT JOIN orders bo`<br>`INNER JOIN channels ch`<br>`LEFT JOIN channeled_stores s` | • `order_count` (`COUNT(DISTINCT o.id)`)<br>• `canonical_order_count` (`COUNT(DISTINCT o.order_id)`)<br>• `revenue` (canonical-deduped when cross-channel, currency-guarded)<br>• `subtotal`, `total_tax`, `total_discount`<br>• `aov` (`revenue / canonical_order_count`)<br>• `items_sold`, `units_sold`<br>• `unique_customers` | `channel`, `store`, `financial_status`, `fulfillment_status`, `currency`, `product`, `discount_code` | `channel`, `store_id`, `order_id`, `customer_id`, `channeled_customer_id`, `product_id`, `discount_code`, `financial_status`, `fulfillment_status`, `currency` | `COALESCE(o.data->>'processed_at_foreign', o.platform_created_at, o.created_at)` |
| **`customers`** | `channeled_customers` `cc`<br>`LEFT JOIN customers c`<br>`INNER JOIN channels ch` | • `customer_count` (`COUNT(DISTINCT c.id)`)<br>• `channeled_customer_count` (`COUNT(DISTINCT cc.id)`)<br>• `repeat_customer_count` ($>1$ order)<br>• `high_frequency_customer_count` ($\ge N$ orders, default $3$)<br>• `repeat_buyer_rate`<br>• `customer_ltv` (total spend across stores, currency-guarded)<br>• `avg_revenue_per_customer` (cross-channel avg spend)<br>• `avg_orders_per_customer`<br>• `pre_purchase_touches` (total & avg touches before 1st order) | `channel`, `store`, `status`, `frequency_bucket` (`1`, `2`, `3+`), `touch_channel` | `channel`, `store_id`, `customer_id`, `status`, `min_orders`, `currency` | `COALESCE(cc.platform_created_at, cc.created_at)` |
| **`stores`** | `channeled_stores` `s`<br>`INNER JOIN channels ch` | • `store_count`<br>• `store_revenue`, `store_order_count`, `store_aov`<br>• `store_product_count`, `store_customer_count`<br>• `store_repeat_buyer_rate` | `channel`, `store`, `currency` | `channel`, `store_id`, `currency` | `s.created_at` |
| **`products`** | `channeled_products` `p`<br>`LEFT JOIN products bp`<br>`INNER JOIN channels ch` | • `product_count`, `canonical_product_count`, `variant_count`<br>• `avg_price`, `min_price`, `max_price` (currency-guarded)<br>• `orders_containing_product`<br>• `product_revenue`, `product_units_sold` | `channel`, `store`, `vendor`, `product_type`, `currency` | `channel`, `store_id`, `product_id`, `vendor`, `product_type`, `currency` | `COALESCE(p.platform_created_at, p.created_at)` |
| **`discounts`** (`promotions` / `promo-codes`) | `channeled_discounts` `d`<br>`LEFT JOIN channeled_price_rules pr`<br>`INNER JOIN channels ch` | • `discount_count`, `usage_count`<br>• `orders_with_discount`<br>• `discounted_revenue`, `total_discount_amount` (currency-guarded) | `channel`, `store`, `status`, `type`, `code` | `channel`, `store_id`, `code`, `status` | `COALESCE(d.platform_created_at, d.created_at)` |
| **`campaigns`** | `channeled_campaigns` `c`<br>`INNER JOIN channels ch` | • `campaign_count`<br>• `budget_sum`, `avg_budget` (currency-guarded)<br>• `ad_group_count`, `ad_count` | `channel`, `status`, `objective`, `buying_type` | `channel`, `campaign_id`, `status`, `objective`, `buying_type` | `COALESCE(c.start_date, c.created_at)` |
| **`ad-sets` / `ad-groups`** | `channeled_ad_groups` `ag`<br>`INNER JOIN channels ch` | • `ad_group_count`, `ad_count`, `budget_sum` | `channel`, `status`, `optimization_goal`, `billing_event` | `channel`, `campaign_id`, `ad_group_id`, `status` | `COALESCE(ag.start_date, ag.created_at)` |
| **`ads`** | `channeled_ads` `a`<br>`INNER JOIN channels ch` | • `ad_count` | `channel`, `status`, `campaign_id`, `ad_group_id` | `channel`, `campaign_id`, `ad_group_id`, `ad_id`, `status` | `a.created_at` |
| **`posts`** | `posts` `p`<br>`INNER JOIN channeled_accounts ca` | • `post_count` | `media_type` | `page_id`, `media_type` | `COALESCE(p.data->>'created_time', p.data->>'timestamp', p.created_at)` |
| **`events`** | `channeled_events` `e`<br>`INNER JOIN channels ch` | • `event_occurrence_count`, `distinct_event_names`, `unique_event_actors` | `channel`, `event_name`, `action` | `channel`, `event_name`, `customer_id` | `COALESCE(e.data->>'timestamp', e.platform_created_at, e.created_at)` |

---

## 5. Sub-Plans by Application Context

### 5.1 Sub-Plan A: Custom Dashboards, Derived Metrics & KPIs (`JointDashboard` & `DashboardWidgetDataController`)
1. **Remote Engine Transport (`RemoteEngineService`)**:
   - Add `getCatalogAggregateCapabilities()` and `aggregateCatalogEntities()` with `method_exists` SDK check + `performRequest` fallback.
2. **Direct Entity Measure Widgets (`DashboardWidgetDataController`)**:
   - Support `source_type === 'entity_measure'` (and `handleEntitySource` when configured for entity measures) across `stat` (with previous-period comparison delta), `line`/`bar` (`time_bucket: 'day'|'week'|'month'`), and `pie`/`donut`/`table` (`dimensions`).
3. **Multi-Series Blending (`handleMultiSeriesSource`)**:
   - Support `type: 'entity_measure'` alongside `'metric'` and `'derived_metric'` in `raw_series`, merging daily entity series (`YYYY-MM-DD`) onto the same axis as standard metrics.
4. **Derived Metrics (`handleDerivedMetricSource` & `DerivedMetricPreviewController`)**:
   - Allow `$sourceSeries` entries with `source_type: 'entity_measure'` to resolve via `aggregateCatalogEntities` and feed into `FormulaEvaluator` alongside standard metrics (unlocking Blended ROAS, CAC, POAS, Discount Ratio).
5. **Statistical KPIs (`KpiPayloadBuilder`)**:
   - Allow KPI definitions to monitor daily time-series from `entity_measure` or `derived_metric` sources.

---

### 5.2 Sub-Plan B: Entity Page Summaries (`CatalogEntity` — List Mode vs. Detail Mode)
1. **Unlock Cross-Channel Selection**:
   - Update `CatalogEntity::getChannelOptionsForEntity()` and `mount()` so commerce and customer entities support `'' => __('All channels')` in addition to per-channel filtering, enabling both unified canonical rollups and channel-by-channel comparison.
2. **Purpose-Built List Mode (Stage 2 Population) vs. Detail Mode (Stage 1 Single-Entity 360°)**:
   - **Orders**:
     - *List Mode*: Deduplicated Canonical Orders vs. Channel Order rows, Gross Revenue, **AOV**, Total Discounts, and Cross-Channel / Financial Status breakdown pills.
     - *Detail Mode (Single Order — never shows AOV)*: Order financial breakdown (subtotal, discount, tax, total, units), **Cross-Channel Order Presence Matrix** (every channel referencing this canonical `order_id`—Shopify, Mailchimp, GA4, Google Ads—and whether amounts match), plus Buyer Context (customer link, lifetime order count, and cross-store LTV).
   - **Customers**:
     - *List Mode*: Canonical Customers vs. Channel Profiles, **Average Revenue per Customer across channels**, Repeat Buyer Rate ($>1$ order) & High-Frequency Buyers ($\ge 3$ orders), Average Pre-Purchase Touches, and Channel comparison breakdown.
     - *Detail Mode (Customer 360)*: **Total Purchases & LTV across multiple stores** (unified total + per-store breakdown), **Multi-Channel Touches Prior to Purchase** broken down by channel (e.g., Mailchimp email opens/clicks, Klaviyo automation touches, Meta Lead submissions), and linked cross-channel profiles.
   - **Stores**:
     - *List Mode*: Connected stores count, combined revenue, order volume, blended AOV.
     - *Detail Mode*: Store-specific revenue, order velocity, store AOV, repeat buyer rate, and catalog density (products, variants, promo codes).
   - **Products**:
     - *List Mode*: Catalog SKU/variant count, price range (`avg_price`, `min`–`max`), total orders & units sold across products.
     - *Detail Mode*: Orders containing this specific product, units sold, product revenue generated, and store/channel availability.
   - **Promotions / Promo Codes**:
     - *List Mode*: Active promo codes, total redemptions, total discount amount across stores.
     - *Detail Mode*: Orders redeeming this code, discounted order revenue, and total discount amount granted.
   - **Campaigns / Ad Sets / Ads / Posts / Events**:
     - *List Mode*: Status breakdown, committed budget sum (`budget_sum`), child hierarchy counts, post media-type mix, event occurrence totals.
     - *Detail Mode*: Campaign child ad-groups/ads count & budget vs. spend; Event occurrence count & unique actors.

---

### 5.3 Sub-Plan C: DataPreview Pages (Single-Channel Cache Confidence)
Each channel DataPreview page gets a **"Cached Entity Verification & Contextual Measures"** panel powered by `/api/catalog/aggregate` scoped to that channel:
- **Mailchimp (`MailchimpDashboard`)**: Audience subscribed vs. unsubscribed count, Connected Store orders/revenue/AOV, Promo code redemptions & discount totals, and Pre-purchase campaign touches count.
- **Meta Ads (`FacebookMarketingDashboard`)**: Cached hierarchy inventory (campaigns by status, ad sets, active ads), Planned budget total (`budget_sum`), and synced Meta Lead customer records.
- **Meta Organic (`FacebookOrganicDashboard`)**: Cached posts count broken down by `media_type` (Video, Image, Carousel/Album) and posting cadence.
- **Google Ads (`GoogleAdsDashboard`)**: Cached campaigns, ad groups, and ads counts by status + configured campaign budget sum.
- **Google Analytics (`GoogleAnalyticsDashboard`)**: Distinct cached event names and total recorded event occurrences.
- **Google Search Console (`GoogleSearchConsoleDashboard`)**: Cached search query and page coverage counts.

---

## 6. Phased Execution Roadmap (Completed & Verified)

- [x] **Phase 1 — Worker (`apis-hub`) Two-Stage & Cross-Channel Engine**:
  - Upgraded `EntityMeasureCatalog` and `EntityAggregationEngine` to implement the Two-Stage (Entity Rollup $\rightarrow$ Population Aggregation) and Dual-Grain (Canonical Deduplicated vs. Channel Breakdown) model with all 5 technical guardrails (`channels.id` FK join, polymorphic customer scope, customer-specific pre-purchase touches by channel, `EXISTS` join fan-out guard, and multi-currency nulling guard).
  - Expanded `EntityAggregationEngineTest` to verify cross-channel canonical deduplication, multi-store customer LTV, customer-specific pre-purchase touches across channels, mixed-currency protection, and multi-line order filtering (`10 tests, 53 assertions passing`).
- [x] **Phase 2 — SDK (`apis-hub-api`) & Facade Transport (`RemoteEngineService`)**:
  - Added `getCatalogAggregateCapabilities` and `aggregateCatalogEntities` to `ApisHubApi` (`15 tests passing`) and `RemoteEngineService`.
- [x] **Phase 3 — Facade Context B (`CatalogEntity` List & Detail Summaries + Cross-Channel Support)**:
  - Unlocked cross-channel (`All commerce channels`) mode on commerce/customer entities and implemented `loadListSummary()` and `loadDetailSummary()` + Blade views (`catalog-list-summary.blade.php`, `catalog-detail-summary.blade.php`) and `CatalogEntityPageTest` (`14 tests passing`).
- [x] **Phase 4 — Facade Context C (DataPreview Confidence Panels)**:
  - Computed Store `aov` in `MailchimpController` & `mailchimp-dashboard.blade.php` and wired `entity_measures` cache verification into `MailchimpController`, `FacebookMarketingController`, `FacebookOrganicController`, `GoogleAdsController`, and `GoogleAnalyticsController`.
- [x] **Phase 5 — Facade Context A (Custom Dashboards, Derived Metrics & KPIs)**:
  - Wired `entity_measure` into `DashboardWidgetDataController` (single-source, multi-series overlay, derived metrics, and KPIs), `DerivedMetricPreviewController`, and `KpiPayloadBuilder`, verified by `EntityMeasuresIntegrationTest`.
