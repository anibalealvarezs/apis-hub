# Meta Leads (Facebook Leads): Discovery & Master Implementation Plan

> **APIs Hub SaaS Hierarchy:** Communication (`facebook-graph-api`) $\rightarrow$ Normalization (`meta-hub-driver`) $\rightarrow$ Abstraction (`api-driver-core`) $\rightarrow$ Orchestration (`apis-hub`) $\rightarrow$ Business (`apis-hub-facade`)  
> **Dual Architecture Standard:** Macro Pre-Aggregated Analytics + Micro Human Journey & Attribution Ingestion

---

## 🗺️ Progression Dynamic & State Tracker

| Stage | Focus Area | Status | Target Package |
| :--- | :--- | :--- | :--- |
| **Phase 0** | Comprehensive Source API & Domain Analysis | `COMPLETED` | Architectural Specification |
| **Stage 1** | Meta SDK (`facebook-graph-api`) Audit & Verification | `PENDING` | `D:\laragon\www\facebook-graph-api` |
| **Stage 2** | Driver Modernization (`meta-hub-driver`) | `PENDING` | `D:\laragon\www\meta-hub-driver` |
| **Stage 3** | APIs Hub Core Engine Integration (`apis-hub`) | `PENDING` | `D:\laragon\www\apis-hub` |
| **Stage 4** | Facade UI, Data Sources & Data Explorer (`apis-hub-facade`) | `PENDING` | `D:\laragon\www\apis-hub-facade` |
| **Stage 5** | Multi-Level Testing & Quality Gate | `PENDING` | Cross-Workspace Testing |

---

## 🔍 Phase 0: Comprehensive Source API & Domain Analysis

### 0.1 Provider Purpose & Marketing Value Proposition
- **Domain Identity:** Meta Lead Ads enable businesses to capture prospect contact info and qualification answers directly within Facebook and Instagram via high-converting instant forms.
- **SaaS Role:** Operates as the **Inbound Acquisition Anchor** in APIs Hub. It bridges paid advertising spend (`facebook_marketing`) with human identity records (`channeled_customers`) and lifecycle nurturing (`mailchimp`, `klaviyo`).
- **Primary Use Cases:**
  1. *Executive Analytics:* Daily lead volumes, lead generation velocity, Cost Per Lead (CPL) when joined with marketing spend, and form conversion efficiency.
  2. *Human Journey (CDP):* The "First Handshake" of the customer lifecycle—storing real prospect attributes, qualification responses, and originating ad attribution.

### 0.2 Entity & Metric Surface Availability
- **Master Entity Hierarchy:**
  ```
  Tenant Project (APIs Hub)
    └── Meta Account Connection
          └── Asset: Facebook Page (channeled_account: facebook_page)
                ├── Leadgen Forms (id, name, status, questions/field_data, created_time)
                │     └── Atomic Leads (id, created_time, form_id, field_data, ad_id, campaign_id)
                │           ├── Profile Attributes: email, phone_number, full_name
                │           ├── Geolocation Fields: city, state, country_code, zip_code (via form field_data)
                │           └── Qualification Responses: budget, timeline, company, custom questions
                └── Linked Marketing Campaigns (ad_id, adset_id, campaign_id)
  ```
- **Metric Time-Series Nature:**
  - Meta exposes lead submissions as **immutable, atomic event streams** (`/{form-id}/leads`).
  - There is no native daily metric time-series endpoint for lead form responses; therefore, the driver must stream atomic leads and feed the **Agnostic Pre-Aggregation Engine** to generate daily/hourly performance cubes.

### 0.3 Technical Challenges for Ingestion & Data Caching
- **The 90-Day Retention Constraint:** Meta Graph API stores lead submissions for **strictly 90 days**. Any lead older than 90 days is purged by Meta and cannot be queried.
  - *Strategy:* Capped initial historic fetch at 90 days. Once ingested into APIs Hub's isolated database, the records are preserved indefinitely.
- **Looping & Pagination:** Cursor-based (`after`). Standardized with the mandatory infinite loop guard:
  ```php
  if ($after !== null && $after === $prevAfter) {
      break; // Infinite pagination guard
  }
  ```
- **Rate Limits & Worker Concurrency:** Meta Graph API enforces application-level rate limits based on call volume per active user.
  - Set `getDefaultMaxWorkers(): int` to **2** to prevent rate-limit throttling during multi-page syncs.

### 0.4 Metrics Canonicalization & APIs Hub Alignment
- **Canonical Metric Dictionary (`getCanonicalMetricDictionary`):**
  - Strictly disambiguated 1:1 mapping (preventing SQL double-counting):
    ```php
    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'leads'         => ['leads_total'],
            'leads_paid'    => ['leads_paid'],
            'leads_organic' => ['leads_organic'],
        ];
    }
    ```
- **Cross-Channel Equivalence:**
  - `facebook_leads:leads_total` $\leftrightarrow$ `linkedin:leads_total`
  - When joined with `facebook_marketing:spend`:
    $$\text{CPL (Cost Per Lead)} = \frac{\text{facebook\_marketing:spend}}{\text{facebook\_leads:leads\_total}}$$

### 0.5 Dual Ingestion: The Human Journey Architecture
To satisfy the Human Journey mandate, the driver emits two collections to `$this->dataProcessor`:
1. **The Human Node (`channeled_customer`):**
   - Natural identity hash: $\text{identity\_hash} = \text{MD5}(\text{LOWER}(\text{TRIM}(\text{email})))$.
   - Normalized profile data (`name`, `email`, `phone`, `city`, plus JSON qualifiers in `lead_data`).
2. **The Touchpoint Node (`channeled_event`):**
   - Action: `submitted_lead_form`
   - Scoped to `channeled_account_id` (Page ID) with timestamp (`created_time`).
   - Causal Attribution: Persists `campaign_id`, `adset_id`, and `ad_id` directly in the event context.

### 0.6 Meta App Review Strategy (`leads_retrieval`)
To overcome Meta's strict review barriers for PII permissions:
- **Tenant Isolation Narrative:** Showcase that APIs Hub provisions an isolated database per project. No data is cross-pollinated or shared.
- **Legitimate UI Purpose:** Implement a dedicated **"Lead Submissions" (CRM View)** tab in Data Explorer alongside aggregate charts to prove `leads_retrieval` is actively rendered to the authenticated user.
- **Dedicated Screen Recording Script:** Update `apis-hub-facade/docs/meta_review_script.md` with an exact 60-second walkthrough.

---

## 📌 Stage 1: Meta SDK (`facebook-graph-api`) Audit & Verification

### Goal
Verify that `FacebookGraphApi` provides robust, loop-guarded methods for querying Facebook Pages, Leadgen Forms, and Form Leads with ad attribution fields.

### Tasks
- [ ] **1.1 Leadgen API Endpoints in `FacebookGraphApi.php`**
  - Verify `getPageLeadgenForms(string $pageId, array $fields = [])`:
    - Requests fields: `['id', 'name', 'status', 'created_time', 'leads_count', 'questions']`.
  - Verify `getFormLeads(string $formId, int $limit = 100, ?string $after = null, array $fields = [])`:
    - Ensure fields request: `['id', 'created_time', 'field_data', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name', 'is_organic']`.
  - Add `getAllFormLeadsAndProcess(string $formId, callable $callback, int $limit = 100)`:
    - Incorporates `updatePaginationQuery` loop guard to prevent repeating pagination cursors.
- [ ] **1.2 SDK Quality Gate**
  - Unit test `FacebookGraphApiLeadsTest.php` mocking form and lead responses with pagination.

---

## 📌 Stage 2: Driver Modernization (`meta-hub-driver`)

### Goal
Upgrade `FacebookLeadsDriver` to conform to modern `api-driver-core` standards, dual-format `updateConfiguration()`, explicit string typing in `dataProcessor`, and pre-aggregation rules.

### Tasks
- [ ] **2.1 Configuration & Asset Management (`updateConfiguration`)**
  - Update `updateConfiguration()` to handle both nested `channels.facebook_leads` and flat root configurations.
  - Unpack selected assets: map `assets.pages` into the canonical `pages` configuration key.
  - Implement `fetchAvailableAssets()` returning `pages` with `id`, `name`, `tasks`, and leadgen capabilities.
- [ ] **2.2 Agnostic Identification & Asset Patterns**
  - Align with `AssetCategory`:
    - `AssetCategory::IDENTITY` / `PAGEABLE` $\rightarrow$ Facebook Page (`id`, `title`).
    - `AssetCategory::CAMPAIGN` $\rightarrow$ Leadgen Form (`form_id`, `name`).
    - `AssetCategory::UNIT` $\rightarrow$ Lead Submission (`lead_id`).
- [ ] **2.3 Dual Conversion & Entity Persistence (`sync`)**
  - Implement `syncEntities()`:
    - Fetch forms for each enabled page and persist via `($this->dataProcessor)($formsCollection, 'leadgen_form')`.
  - Implement `sync()`:
    - Ingest leads within the chunked time window (constrained to max 90 days for initial sync).
    - Parse dynamic `field_data` to extract `email`, `phone_number`, `first_name`, `last_name`, and custom questions into `lead_data`.
    - Compute `identity_hash = md5(strtolower(trim($email)))`.
    - **Micro/Journey Track:** Dispatch to `($this->dataProcessor)($convertedCustomers, 'channeled_customer')` and `($this->dataProcessor)($convertedEvents, 'channeled_event')`.
    - **Macro/Aggregate Track:** Pass events to the Pre-Aggregation Engine for daily rollups (`leads_total`, `leads_paid`, `leads_organic`).
  - Add `$shouldContinue()` orchestrator abort checks on each form and lead chunk iteration.
- [ ] **2.4 Metric Profiles & Dictionary**
  - Implement `CanonicalMetricDictionaryProviderInterface` returning `leads`, `leads_paid`, `leads_organic`.
  - Implement `AggregationProfileProviderInterface` returning `fb_leads_summary` and `fb_leads_form_breakdown`.
  - Declare `getDefaultMaxWorkers()` returning `2`.
- [ ] **2.5 Driver Quality Gate**
  - Unit tests: `FacebookLeadsConvertTest.php` (parsing `field_data`, hash generation, attribution preservation).
  - Sync tests: `FacebookLeadsDriverSyncTest.php` (verifying `dataProcessor` receives `'channeled_customer'` and `'channeled_event'`).

---

## 📌 Stage 3: APIs Hub Core Engine Integration (`apis-hub`)

### Goal
Register `facebook_leads` in the worker pipeline, create database migration routines following the Many-to-One funnel architecture, and provide a synthetic data seeder.

### Tasks
- [ ] **3.1 Configuration & Enums**
  - Verify driver mapping in `config/drivers.yaml`:
    ```yaml
    facebook_leads:
      class: Anibalealvarezs\MetaHubDriver\Drivers\FacebookLeadsDriver
    ```
  - Ensure `Channel::FACEBOOK_LEADS` exists in channel enums.
- [ ] **3.2 Database Migration Routine (`Upgrade_v1_18_1_to_v1_18_2` or target release)**
  - Implement idempotent Many-to-One migration:
    - Add channeled indexes on `channeled_customers` and `channeled_events` for channel `facebook_leads`.
    - Ensure provider `meta` and channel `facebook_leads` exist in `channels` table.
- [ ] **3.3 Synthetic Lead Seeder**
  - Implement `SeedFacebookLeadsMetricsCommand` implementing `SeederInterface`:
    - Generates realistic synthetic lead forms and timestamped leads with email, phone, and UTM attribution for local dev/staging testing.
- [ ] **3.4 Worker Pipeline Verification**
  - Unit tests verifying `DriverFactory::get('facebook_leads')` resolves cleanly.

---

## 📌 Stage 4: Facade UI, Data Sources & Data Explorer (`apis-hub-facade`)

### Goal
Expose Facebook Leads in Data Sources with version gating, register official Meta Leads branding, and build the standard 3-tier Data Explorer dashboard.

### Tasks
- [ ] **4.1 Channel Profile & Tenant Version Gating**
  - Register `FacebookLeadsProfile` in `ChannelProfileRegistry` via `AppServiceProvider`.
  - Set version gating in `DataSources.php` (e.g. requires APIs Hub version $\ge$ `v1.18.2`).
- [ ] **4.2 Data Sources UI & Branding**
  - Register official Meta Leads SVG icon in `app/Support/BrandIcon.php` and `resources/views/filament/app/pages/data-sources.blade.php`.
  - Display page asset toggles with Page IDs and Leadgen status badges.
- [ ] **4.3 Standard Data Explorer Dashboard (`FacebookLeadsDashboard.php`)**
  - Create Filament Page under `DataExplorer` cluster (`app/Filament/App/Pages/FacebookLeadsDashboard.php`).
  - Create Blade view (`resources/views/filament/app/pages/facebook-leads-dashboard.blade.php`):
    1. **Summary Tiles (KPI Cards):** Total Leads, Paid Leads, Organic Leads, Active Forms, Est. CPL (blended with Meta Ads).
    2. **Time-Series Performance Line Chart:** Dynamic daily/weekly/monthly lead submission velocity.
    3. **Scoped Breakdown Tables:**
       - Tab 1: **Forms Breakdown** (Form Name, Status, Total Leads, Conversion Rate).
       - Tab 2: **Campaign Attribution** (Originating Ad Campaign, Ad Set, Ad, Leads Generated).
       - Tab 3: **Lead Submissions (CRM / Journey View)** (Timestamp, Contact Name, Email, Phone, Form, Key Responses)—*crucial for Meta App Review approval*.
  - Create frontend script (`resources/js/dashboards/facebook-leads-dashboard.js`) with date range filtering and session caching.
- [ ] **4.4 API Aggregation Controller**
  - Expose `/api/facebook-leads/{summary,chart,table}` in `routes/web.php` guarded by `channel.asset.access:facebook_leads`.
  - Dispatch queries to `RemoteEngineService::aggregateChanneledPool()` with `channel: 'facebook_leads'`.
- [ ] **4.5 Meta App Review Documentation**
  - Update `docs/meta_review_script.md` with the dedicated walkthrough demonstrating the legitimate necessity of `leads_retrieval`.

---

## 📌 Stage 5: Multi-Level Testing & Quality Gate

### Pre-Release Verification Checklist
- [ ] **SDK Unit Tests:** 100% passing tests for lead pagination loop guards and error classification.
- [ ] **Driver Syncing Tests:** Verified `syncEntities()` and `sync()` dispatch valid `channeled_customer` and `channeled_event` collections.
- [ ] **Data Sources Persistence:** Saving page toggles updates YAML without dropping accounts or tokens.
- [ ] **Tenant Version Gating:** Tenants running older releases cleanly show "Upgrade Required".
- [ ] **Dashboard Aggregation & CRM View:** KPI cards, charts, and table tabs display accurate numbers without duplicate metric summation.
- [ ] **Database Integrity:** Verified `identity_hash` is correctly populated on all ingested customer records for downstream journey joins with Mailchimp and Shopify.
