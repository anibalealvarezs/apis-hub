# Klaviyo Hub Driver: Discovery & Master Implementation Plan

> **APIs Hub SaaS Hierarchy:** Communication (`klaviyo-api-anibal`) $\rightarrow$ Normalization (`klaviyo-hub-driver`) $\rightarrow$ Abstraction (`api-driver-core`) $\rightarrow$ Orchestration (`apis-hub`) $\rightarrow$ Business (`apis-hub-facade`)  
> **Architectural Paradigm:** Hybrid Native Metric Aggregates + Derivative Entity & Event-Driven Caching  
> **Core Identity Key:** $\text{identity\_hash} = \text{MD5}(\text{LOWER}(\text{TRIM}(\text{email\_address})))$

---

## 🗺️ Progression Dynamic & State Tracker

| Stage | Focus Area | Status | Target Package |
| :--- | :--- | :--- | :--- |
| **Phase 0** | Hybrid Dual-Track Domain & API Analysis | `COMPLETED` | Architecture Specification |
| **Stage 1** | SDK Verification (`klaviyo-api-anibal`) | `PENDING` | `D:\laragon\www\klaviyo-api-anibal` |
| **Stage 2** | Driver Modernization (`klaviyo-hub-driver`) | `PENDING` | `D:\laragon\www\klaviyo-hub-driver` |
| **Stage 3** | APIs Hub Core & Channeled Entities Integration (`apis-hub`) | `PENDING` | `D:\laragon\www\apis-hub` |
| **Stage 4** | Facade UI, Data Sources & Dual-Explorer (`apis-hub-facade`) | `PENDING` | `D:\laragon\www\apis-hub-facade` |
| **Stage 5** | Multi-Level Testing & Quality Gate | `PENDING` | Cross-Workspace Testing |

---

## 🔍 Phase 0: Hybrid Dual-Track Domain & API Analysis

### 0.1 Provider Purpose & Marketing Value Proposition
- **Domain Identity:** Klaviyo is an intelligent marketing automation and customer data platform (CDP) built specifically for eCommerce. It connects deeply with Shopify, WooCommerce, and custom stores to track live behavioral events, trigger dynamic email/SMS flows, and compute predictive analytics.
- **The Dual-Track Architecture in Klaviyo:**
  Unlike Mailchimp (which forces raw event rollups on the client) and unlike Google Ads (which only returns pre-computed numbers), **Klaviyo provides a powerful dual architecture**:
  1. **Track A (Native Metric-Aggregates):** A high-performance OLAP endpoint (`POST /api/metric-aggregates/`) returning daily time-series counts and financial sums sliced by campaigns or flows without pulling raw events.
  2. **Track B (Derivative Entity Caching):** Rich contextual payloads embedded in customer profiles and event streams (`Placed Order`, `Started Checkout`, `Added to Cart`) that allow APIs Hub to discover and cache **Customers**, **Products**, **Variants**, **Categories**, and **Orders** directly from the marketing stream.

### 0.2 Entity & Metric Surface Availability
- **Master Entity Hierarchy:**
  ```
  Tenant Project (APIs Hub)
    └── Klaviyo Account Connection (API Key: pk_xxx)
          ├── Audiences & Segments (channeled_accounts: list, segment)
          │     └── Customers / Profiles (channeled_customers -> Customer)
          │           ├── email, phone_number, first_name, last_name
          │           ├── Geolocation Data: city, region/state, country, zip_code, timezone, IP-derived geo
          │           ├── predictive_analytics: churn_risk, next_order_date, historic_clv
          │           └── identity_hash = MD5(LOWER(TRIM(email)))
          ├── Catalog Entities (Derived from eCommerce sync):
          │     ├── Products (channeled_products -> Product)
          │     ├── Categories (channeled_product_categories -> ProductCategory)
          │     └── Variants / SKUs (channeled_product_variants -> ProductVariant)
          ├── Broadcasts & Automations:
          │     ├── Campaigns (channeled_campaigns: regular)
          │     └── Flows & Flow Messages (channeled_campaigns: automation)
          └── Dual-Ingestion Pathways:
                ├── Track A: Native OLAP Aggregates (POST /api/metric-aggregates)
                └── Track B: Atomic Interaction Events (/api/events)
  ```

### 0.3 The Technical Caching & Ingestion Strategy

#### Track A: Fast Metric Aggregates (`syncMetrics`)
- Uses Klaviyo's native `POST /api/metric-aggregates/` endpoint:
  ```json
  {
    "data": {
      "type": "metric-aggregate",
      "attributes": {
        "metric_id": "{placed_order_id}",
        "interval": "day",
        "measurements": ["count", "sum_value"],
        "filter": [
          "greater-than(datetime, 2026-09-01T00:00:00Z)",
          "less-than(datetime, 2026-10-01T00:00:00Z)"
        ],
        "by": ["$attributed_message"]
      }
    }
  }
  ```
- **Benefit:** Instantaneous historical time-series generation for high-level dashboard charts with negligible network transfer and zero client-side calculation overhead.

#### Track B: Derivative Entity & Event Caching (`syncCustomers`, `syncProducts`, `syncEvents`)
- **Customer / Profile Ingestion:** Ingests unhashed emails, phones, subscriptions, and predictive analytics into `channeled_customers`.
- **eCommerce Event Ingestion:** Streams atomic conversion events (`Placed Order`, `Started Checkout`) into `channeled_events` with order IDs, revenue values, and line item context, computing `identity_hash` for cross-channel timeline joins.
- **Catalog Ingestion:** Ingests synced catalog items into `channeled_products` and `channeled_product_variants`.

### 0.4 Metrics Canonicalization & APIs Hub Alignment
- **Disambiguated Canonical Metric Dictionary:**
  ```php
  public static function getCanonicalMetricDictionary(): array
  {
      return [
          'sends'          => ['sends'],
          'opens'          => ['opens_total'],
          'clicks'         => ['clicks_total'],
          'bounces'        => ['bounces_total'],
          'unsubscribes'   => ['unsubscribes'],
          'placed_orders'  => ['orders_count'],
          'revenue'        => ['revenue'],
          'checkouts'      => ['checkouts_started'],
          'product_views'  => ['product_views'],
      ];
  }
  ```

---

## 📌 Stage 1: SDK Verification (`klaviyo-api-anibal`)

### Tasks
- [ ] **1.1 Standardize API Revision:** Target stable revision `2024-10-15` (or latest JSON:API standard).
- [ ] **1.2 Verify `metric-aggregates` Builder:**
  - Verify `getMetricAggregates()` sends properly structured JSON:API payloads with measurements (`count`, `sum_value`) and grouping attributes (`$attributed_message`, `$attributed_flow`).
- [ ] **1.3 Add Atomic Event & Profile Streaming:**
  - `getAllProfilesAndProcess(callable $callback, array $additionalFields = ['predictive_analytics'])`.
  - `getAllEventsForMetricAndProcess(string $metricId, callable $callback, ?string $since)`.
  - Implement cursor-based pagination loop guards (`$nextCursor === $prevCursor`).
- [ ] **1.4 Error Classification:**
  - `KlaviyoErrorClassifier` handling 401/403 as `permanent_auth` and 429 as `retryable` with `Retry-After`.

---

## 📌 Stage 2: Driver Modernization (`klaviyo-hub-driver`)

### Tasks
- [ ] **2.1 Configuration & Multi-Account Support (`updateConfiguration`)**
  - Implement `MultiAccountAuthProviderInterface` with token storage in `storage/tokens/klaviyo_tokens.json`.
  - Support both nested `channels.klaviyo` and flat configs, unpacking `assets.lists`.
  - Implement `fetchAvailableAssets()` returning configured lists and segments.
- [ ] **2.2 Derivative Entity Synchronization (`syncEntities`)**
  - Modernize existing `syncCustomers()`, `syncProducts()`, and `syncProductVariants()`:
    - Pass explicit entity string types: `($this->dataProcessor)($collection, 'channeled_customer')`.
    - Pass explicit entity string types: `($this->dataProcessor)($collection, 'product')` and `'product_variant'`.
    - Compute `identity_hash = md5(strtolower(trim($email)))` on all customer profiles.
- [ ] **2.3 Dual Metric Synchronization (`sync`)**
  - **Track A (Aggregates):** Call `syncMetrics()` using `metric-aggregates` for daily performance rollups (`sends`, `opens`, `clicks`, `revenue`).
  - **Track B (Atomic Events):** Stream atomic events (`Placed Order`, `Started Checkout`) into `channeled_events` with `identity_hash` and campaign attribution.
  - Implement `$shouldContinue()` cancellation checks across all metric and profile loops.
- [ ] **2.4 Metric Profiles & Dictionary**
  - Declare `getCanonicalMetricDictionary()` with strict 1:1 rollup keys.
  - Declare `getAggregationProfiles()` with flow and campaign breakdown capabilities.
  - Declare `getDefaultMaxWorkers()` returning `2`.

---

## 📌 Stage 3: APIs Hub Core Engine Integration (`apis-hub`)

### Tasks
- [ ] **3.1 Driver Registration:** Register `klaviyo` under `config/drivers.yaml`.
- [ ] **3.2 Database Migration Routine (`Upgrade_vX_to_vY`)**:
  - Add indexes on `channeled_customers`, `channeled_events`, `channeled_products` for channel `klaviyo`.
  - Seed provider `klaviyo` and channel `klaviyo` in `channels` table.
- [ ] **3.3 Synthetic Data Seeder:**
  - Implement `SeedKlaviyoMetricsCommand` generating synthetic flows, customer profiles, predictive analytics, and conversion events.
- [ ] **3.4 Worker Pipeline Verification:** Verify `DriverFactory::get('klaviyo')` resolves cleanly.

---

## 📌 Stage 4: Facade UI & Data Explorer (`apis-hub-facade`)

### Tasks
- [ ] **4.1 Channel Profile & Version Gating:**
  - Register `KlaviyoProfile` in `ChannelProfileRegistry`.
  - Add tenant release version gating in Data Sources.
- [ ] **4.2 Data Sources UI & Branding:**
  - Register official SVG Klaviyo mark in `BrandIcon.php` and `data-sources.blade.php`.
  - Support private API key entry with validation notification and list selection.
- [ ] **4.3 Standard Data Explorer Dashboard (`KlaviyoDashboard.php`):**
  - Summary Tiles: Total Sends, Open Rate, CTOR, Placed Orders, Attributed Revenue, Churn Risk.
  - Velocity Chart: Daily Email vs. SMS engagement and revenue over time.
  - Tabbed Breakdowns:
    - Tab 1: **Campaigns** (Broadcast sends, opens, clicks, revenue).
    - Tab 2: **Automations / Flows** (Flow steps, drop-off, conversions).
    - Tab 3: **Profiles & Customer Journey View** (Customer name, email, predictive CLV, recent touchpoints).
- [ ] **4.4 Web API Controller:**
  - Expose `/api/klaviyo/{summary,chart,table}` guarded by `channel.asset.access:klaviyo`.

---

## 📌 Stage 5: Multi-Level Testing & Quality Gate

### Pre-Release Verification Checklist
- [ ] **SDK Unit Tests:** 100% green tests on `metric-aggregates` request construction and cursor loop guards.
- [ ] **Driver Dual Sync Tests:** Verify both Track A (`metric-aggregates`) and Track B (`channeled_customer`, `channeled_event`) persist with proper string types.
- [ ] **Cross-Channel Reconciler:** Verify that Klaviyo's attributed revenue mathematically aligns with Shopify orders sharing the same `identity_hash`.
- [ ] **Data Sources Persistence:** Test API key updates without dropping configured lists or accounts.
