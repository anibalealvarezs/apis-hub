# Google Ads Integration: Master Implementation Plan

This implementation plan orchestrates the full integration of **Google Ads** analytics, entity hierarchies, rate limiting, and reporting into the APIs Hub ecosystem.

To allow seamless tracking across multiple working sessions, each sub-stage has a **Status Tracker Checkbox (`[ ]` / `[x]`)** and an associated **Test Gate Checklist**.

---

## 🗺️ Progression Dynamic & State Tracker

| Stage | Focus Area | Status | Target Package |
| :--- | :--- | :--- | :--- |
| **Stage 1** | Google SDK (`google-api-anibal`) Update | `COMPLETED` | `D:\laragon\www\google-api-anibal` |
| **Stage 2** | Google Hub Driver (`google-hub-driver`) Update | `COMPLETED` | `D:\laragon\www\google-hub-driver` |
| **Stage 3** | APIs Hub Core Engine & Facade Integration (Release `v1.18.0`) | `IN PROGRESS` | `D:\laragon\www\apis-hub` & `apis-hub-facade` |

---

## 📌 Stage 1: Google SDK (`google-api-anibal`) Update

### Goal
Upgrade `GoogleAdsApi` to support robust GAQL stream execution, infinite loop-guarded pagination, token refresh consistency, and unified error classification (`GoogleErrorClassifier`).

### Implementation Steps

#### 1.1 Error Classification & Exception Handling
- [x] **Enrich `GoogleErrorClassifier`** (`src/Google/Support/GoogleErrorClassifier.php`):
  - Detect Google Ads specific error payloads (`GoogleAdsFailure`, `QuotaErrorEnum.RESOURCE_EXHAUSTED`, `RateLimitError`).
  - Classify transient concurrency errors (e.g. `CONCURRENT_MODIFICATION`, `DATABASE_ERROR`) as `retryable`.
  - Classify permanent auth failures (e.g. `CUSTOMER_NOT_FOUND`, `NOT_ADS_USER`, `DEVELOPER_TOKEN_PROHIBITED`, `OAUTH_TOKEN_REVOKED`, `invalid_grant`) as `permanent_auth`.
  - Handle rate-limit headers (Google Ads `retry-after` header extraction).
- [x] **Create `GoogleAdsRateLimitException`** (`src/Services/GoogleAds/Exceptions/GoogleAdsRateLimitException.php`):
  - Extends `ApiException`, captures `developerToken`, `customerId`, retry-after duration, and error codes.

#### 1.2 Loop Methods & Pagination Guard
- [x] **Standardize Loop & Pagination Logic in `GoogleAdsApi`** (`src/Services/GoogleAds/GoogleAdsApi.php`):
  - Implement protected helper `updatePaginationQuery(&$payload, ?string $nextPageToken, ?string &$prevPageToken): bool` to guard against identical repeating tokens (preventing infinite sync loops).
  - Enhance `searchAllAndProcess(string $customerId, string $query, callable $callback, int $pageSize = 1000)`:
    - Pass through `updatePaginationQuery`.
    - Provide loop exit callbacks for early cancellation (`$shouldContinue`).
  - Add `searchStreamAndProcess(string $customerId, string $query, callable $callback)`:
    - Stream JSON / NDJSON response chunks from `/customers/{customerId}/googleAds:searchStream` directly to minimize memory footprint on large datasets.

#### 1.3 GAQL Query Builders & Typed Service Methods
- [x] **Create `GoogleAdsQueryBuilder`** (`src/Services/GoogleAds/Support/GoogleAdsQueryBuilder.php`):
  - Helper to build valid GAQL syntax for resources (`customer`, `campaign`, `ad_group`, `ad_group_ad`).
  - Safe parameter binding for date segments (`segments.date BETWEEN 'YYYY-MM-DD' AND 'YYYY-MM-DD'`).
  - Device and network segment selectors (`segments.device`, `segments.ad_network_type`).
- [x] **Add Hierarchy Endpoints to `GoogleAdsApi`**:
  - `getHierarchy(string $customerId, array $levels = ['campaign', 'ad_group', 'ad'])`
  - `getMetricsForLevel(string $customerId, string $level, string $startDate, string $endDate, array $metrics, array $segments = [])`

---

### Stage 1 Testing Coverage & Quality Gate
- [x] **Unit Tests (`google-api-anibal/tests/Unit/Services/GoogleAds`):**
  - `GoogleAdsApiPaginationTest.php`:
    - Tests `searchAllAndProcess` iterates through multiple pages correctly.
    - Tests loop guard halts if `nextPageToken === prevPageToken`.
    - Tests callback receives batch arrays without data loss.
  - `GoogleAdsErrorClassifierTest.php`:
    - Tests classification of `RESOURCE_EXHAUSTED` -> `quota`.
    - Tests classification of `CONCURRENT_MODIFICATION` -> `retryable`.
    - Tests classification of `DEVELOPER_TOKEN_PROHIBITED` -> `permanent_auth`.
  - `GoogleAdsQueryBuilderTest.php`:
    - Tests GAQL query generation for each hierarchy level with date and device segments.
- [x] **Integration Tests (`google-api-anibal/tests/Integration/Services`):**
  - `GoogleAdsApiLiveTest.php`:
    - Skips gracefully if credentials (`google_ads_developer_token`, `google_ads_customer_id`, `google_client_id`) are missing or placeholders.
    - Tests live customer accessible list (`getAccessibleCustomers()`).
    - Tests live GAQL query execution via `search()` and `getCampaigns()`.
    - Tests live error parsing when querying an invalid customer ID or malformed GAQL.
- [x] **Static Analysis & Lint:**
  - Run `vendor/bin/phpunit tests/Unit` and verify 100% green test suite (116 tests, 407 assertions passing).

---

## 📌 Stage 2: Google Hub Driver (`google-hub-driver`) Update

### Goal
Upgrade `GoogleAdsDriver` and `GoogleAdsMetricConvert` to achieve full feature parity with `FacebookMarketingDriver`, supporting multi-level ads hierarchy, currency micro conversions, identity mapping via Oracle, chunked processing, and hierarchical validation.

### Implementation Steps

#### 2.1 Metric Conversions (`GoogleAdsMetricConvert`)
- [x] **Revamp `GoogleAdsMetricConvert`** (`src/Conversions/GoogleAdsMetricConvert.php`):
  - Add micro-currency normalization (`cost_micros / 1,000,000`) for spend, CPC, and value.
  - Implement unified entry points matching Facebook marketing style:
    - `customerMetrics(array $rows, ...)`
    - `campaignMetrics(array $rows, ...)`
    - `adGroupMetrics(array $rows, ...)`
    - `adMetrics(array $rows, ...)`
    - `metrics(array $rows, string $level, ...)`
  - Handle dimension extractions (`segments.device` mapped to canonical device names: `desktop`, `mobile`, `tablet`, `connected_tv`).
  - Standardize default metrics to canonical `['spend', 'impressions', 'clicks', 'conversions']`.

#### 2.2 Hierarchical Entity Synchronization (`syncEntities`)
- [x] **Implement Complete Entity Sync in `GoogleAdsDriver`** (`src/Drivers/GoogleAdsDriver.php`):
  - In `syncEntities()`, fetch and structure entities according to `HierarchyType::MARKETING`:
    - `campaign` -> `id`, `name`, `status`, `advertising_channel_type`, `start_date`, `end_date`
    - `ad_group` -> `id`, `name`, `status`, `campaign.id`, `type`
    - `ad` -> `id`, `name`, `status`, `ad_group.id`, `campaign.id`, `type`, `final_urls`

#### 2.3 Production-Grade Metrics Sync (`syncMetrics` & Date Chunking)
- [x] **Align `GoogleAdsDriver::sync()` with `FacebookMarketingDriver` architecture**:
  - Implement Oracle Identity Mapping: batch resolve internal channeled entities via `$identityMapper('channeled_accounts', ...)` before querying.
  - Support date range chunking with `DateHelper::getDateChunks($startDate, $endDate, $chunkSize)`.
  - Process each enabled account through configured levels: `account`, `campaign`, `ad_group`, `ad`.
  - Enforce `validateHierarchicalIntegrity($collection, HierarchyType::MARKETING)` before dispatching to `$this->dataProcessor`.
  - Integrate graceful abort checks (`$shouldContinue()`) on each chunk and level iteration.
  - Add telemetry/logging: record metrics count, rows processed, and duplicates per account chunk.

#### 2.4 Driver Metadata & Configuration Schema
- [x] **Update Driver Metadata & Schema in `GoogleAdsDriver`**:
  - Expand `getCanonicalMetricDictionary()` with full search & video scopes.
  - Fixed GSC hardcoding in `GoogleSyncDriverTrait.php` (made `getApi()` generic/polymorphic across all Google services).
  - Cleaned `GoogleAdsDriver` to consume `GoogleSyncDriverTrait` directly without redundant secondary traits.

---

### Stage 2 Testing Coverage & Quality Gate
- [x] **Unit Tests (`google-hub-driver/tests/Unit`):**
  - `GoogleAdsMetricConvertTest.php`:
    - Tests conversion of `metrics.cost_micros` ($1,500,000 micros = $1.50).
    - Tests device breakdown dimension translation (`MOBILE` -> `mobile`).
    - Tests conversion across hierarchy levels.
  - `GoogleAdsDriverTest.php`:
    - Tests `getMetricProfiles()`, `getAggregationProfiles()`, `getCanonicalMetricDictionary()`.
    - Tests `syncEntities()`, `fetchAvailableAssets()`, and `sync()`.
  - `GoogleAdsDriverSyncTest.php`:
    - Tests chunked date iteration, Oracle entity mapping, `$this->dataProcessor` invocation, and marketing hierarchy validation.
- [x] **Zero Regressions on Sister Google Drivers:**
  - `SearchConsoleDriverCoreTest.php`, `SearchConsoleDriverIdentityTest.php`, `SearchConsoleDriverCanonicalMetricDictionaryTest.php`, `SearchConsoleDriverAggregationProfilesTest.php`, `GoogleSearchConsoleConvertTest.php`: **100% passing (28 tests, 70 assertions)**.

---

## 📌 Stage 3: Integration with APIs Hub Core & Facade (Release `v1.18.0`)

### Goal
Prepare release **`v1.18.0`** across the APIs Hub ecosystem. Register the driver in `apis-hub`, implement database migration routines following the Many-to-One funnel architecture, and expose Google Ads reporting and Data Explorer in `apis-hub-facade`.

### Implementation Steps

#### 3.1 APIs Hub Core Worker Integration (`apis-hub`)
- [x] **Register Driver Configuration (`config/drivers.yaml`)**:
  - Ensured `google_ads` is registered under `drivers.yaml` with its class `Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAdsDriver`.
- [x] **Upgrade Routine `Upgrade_v1_17_0_to_v1_18_0`** (`src/Classes/Upgrades/Routines/Upgrade_v1_17_0_to_v1_18_0.php`):
  - Implemented `getFromVersions(): array` funnelling `['1.17.0', '1.17.1', '1.17.2']`.
  - Set `getToVersion(): string` -> `'1.18.0'`.
  - Declared `requiresNuclearResync(): bool` (false).
  - Executed idempotent SQL statements:
    - Added channeled entity indexes on `channeled_campaigns`, `channeled_ad_groups`, `channeled_ads` for channel `google_ads`.
    - Ensured provider `google` and channel `google_ads` exist in `channels` seed table.
- [x] **Verify Worker Pipeline**:
  - Verified `DriverFactory::get('google_ads')` properly returns `GoogleAdsDriver`.
  - Verified `UpgradePathFinder` discovers the path from 1.17.x to 1.18.0.

#### 3.2 APIs Hub Facade (`apis-hub-facade`)
- [x] **Channel Configuration & Feature Gate**:
  - Added `GoogleAdsProfile` registered in `ChannelProfileRegistry` via `AppServiceProvider`.
  - Added `google_ads` OAuth scopes in `config/services.php`.
- [x] **Data Explorer Page for Google Ads**:
  - Created `app/Filament/App/Pages/GoogleAdsDashboard.php`:
    - Under `DataExplorer` cluster, with slug `google-ads`.
    - Uses `RedirectsWhenChannelDisabled`.
    - Loads enabled Google Ads customer accounts.
  - Created view `resources/views/filament/app/pages/google-ads-dashboard.blade.php`:
    - Top KPI cards: Spend, Impressions, Clicks, CTR, Conversions, ROAS / Conv. Value.
    - Performance line chart for delivery & conversions over time.
    - Hierarchical breakdown tabs: **Campaigns**, **Ad Groups**, **Ads**, **Devices**.
  - Created frontend script `resources/js/dashboards/google-ads-dashboard.js`:
    - State management for date range, active tab, account filter.
    - Async calls to `/api/google-ads/{summary,chart,table}` with session caching.
    - Registered and exposed on `window.googleAdsDashboard` in `app.js`.
- [x] **API Controller & Backend Aggregation**:
  - Created `app/Http/Controllers/Api/GoogleAdsController.php`:
    - Exposed `summary`, `chart`, and `table` endpoints.
    - Query `RemoteEngineService::aggregateChanneledPool()` with `channel: 'google_ads'`.
  - Registered routes in `routes/web.php` guarded by `channel.asset.access:google_ads`.
- [x] **Create Facade Migration for Release v1.18.0**:
  - Created `database/migrations/2026_10_15_000001_register_v1_18_0_release.php`:
    - Inserts `v1.18.0` record into `apis_hub_releases` table.

---

### Stage 3 Testing Coverage & Quality Gate
- [x] **Worker Tests (`apis-hub`):**
  - `tests/Unit/Core/Drivers/DriverFactoryTest.php`: verified `google_ads` resolves `GoogleAdsDriver::class` (100% passing).
  - `tests/Unit/Classes/Upgrades/Upgrade_v1_17_0_to_v1_18_0_Test.php`: verified migration executes `up()` idempotently and rolls back via `down()` (3 tests, 10 assertions passing).
  - `tests/Unit/Classes/Upgrades/UpgradePathFinder_v1_18_0_Test.php`: verified funnel path from `1.17.0`, `1.17.1`, `1.17.2` to `1.18.0` (1 test, 9 assertions passing).
- [x] **Facade Tests (`apis-hub-facade`):**
  - `tests/Feature/Pages/GoogleAdsDashboardAccountsTest.php`: verified account population and filtering (1 passed, 4 assertions).
  - `tests/Feature/Pages/GoogleAdsControllerTest.php`: verified `/api/google-ads/summary`, `/chart`, and `/table` with mock pool aggregation and access middleware (3 passed, 14 assertions).
