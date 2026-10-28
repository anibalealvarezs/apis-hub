# Mailchimp Analytics Domain & Data Model Specification

## 1. Domain Overview & Core Architectural Constraints

Mailchimp’s Marketing API does not natively expose time-series reports pre-sliced by arbitrary date ranges. Native reporting endpoints return cumulative, lifetime aggregates. To provide time-series analytics, historical caching, and multi-dimensional reporting without live API dependency, the system models the domain around **atomic immutable event streams**, **conformed identity keys**, and **independent entity masters**.

---

## 2. Ingestion & Job Synchronization Topology (Entities vs. Metric-Historic)

In APIs Hub, channels with discrete objects separate work into distinct worker jobs:
- **Entities Sync Job (`entities-sync`):** Periodically syncs top-level hierarchical containers, static accounts, pages/lists, and folders.
- **Historic / Chunked Metric Job (`channel-YYYY-Q` or `recent`):** Pulls date-bounded event streams and historical performance.

### Comparison: Facebook Organic vs. Mailchimp vs. Klaviyo

| Ingestion Characteristic | Facebook Organic | Mailchimp | Klaviyo (Future Alignment) |
| :--- | :--- | :--- | :--- |
| **Entities Sync Job** | Caches Pages (`Page`), Posts (`Post`), and Media objects. | Caches Audiences (`ChanneledAudience`), Segments, Folders, Templates, and Connected Stores (`ChanneledStore`). | Caches Lists and Segments. Stores or Campaign associations often exist only as contextual tags/flags. |
| **Metric / Event Ingestion** | Requests daily insights (`page_impressions`, `post_clicks`) pre-aggregated by Facebook. | Ingests **Atomic Recipient Event Streams** (`GET /reports/{campaign_id}/email-activity` or Export API `/1.0/export`) + **eCommerce Orders** (`GET /ecommerce/stores/{store_id}/orders`). | Ingests atomic event streams (`/api/events/`) using metric definitions (`Placed Order`, `Opened Email`). |
| **Entity Discovery Timing** | Entities exist upstream prior to metrics; posts are explicitly discovered in the entities job. | Campaigns and Links exist as objects, but new recipient members or customer records can be discovered dynamically within event streams. | Most chained entities (e.g. ad campaign attribution, external store domain) are **created during the historic event job** by inspecting payload attributes/tags. |
| **Pre-Aggregation Requirement** | None. Facebook returns daily metric time-series ready for `MetricsProcessor`. | **Mandatory.** Raw events (opens, clicks, bounces, orders) must be pre-aggregated into daily buckets by the Agnostic Pre-Aggregation Engine. | **Mandatory.** Event streams must be pre-aggregated into daily time-series before OLAP ingestion. |

---

## 3. Entity Architecture & Relational Topology

The email marketing domain uses a distinct relational topology and must not be conflated with performance ad hierarchies or eCommerce customer domains.

### Master Entity Hierarchy

```
Tenant Project (APIs Hub Instance)
  └── Mailchimp Channel
        ├── Account Connection 1 (API Key A, DataCenter: us4)   ──> [Validated per account]
        │     ├── Asset: Audience / List "Main Subscribers"    ──> [Tracked Asset / Quota Unit]
        │     │     ├── Segments / Tags (Conditional or Static Subsets)
        │     │     └── Email Contacts (Audience-Scoped Member Profiles)
        │     ├── Asset: Audience / List "VIP Club"            ──> [Tracked Asset / Quota Unit]
        │     ├── Asset: Connected Store "Client A Store"       ──> [Tracked Asset / Maps to Canonical Store]
        │     ├── Campaign Folders (Organizational Taxonomy, 0..1 per Campaign)
        │     ├── Templates (Reusable Layouts, M:N across Campaigns)
        │     └── Campaigns (Execution / Dispatch Containers)
        │           └── Target Links / CTAs (Link Destinations, Strictly Scoped 1:N to Campaign)
        │
        └── Account Connection 2 (API Key B, DataCenter: us19)  ──> [Validated per account]
              ├── Asset: Audience / List "EU Newsletter"        ──> [Tracked Asset / Quota Unit]
              └── Asset: Connected Store "Client B Store"       ──> [Tracked Asset / Maps to Canonical Store]
```

### Entity Cardinality & Boundaries

- **Account Connection → Tenant:** Optional 1:N. A single APIs Hub project can connect multiple Mailchimp accounts (e.g., agency managing multiple client accounts within one unified workspace).
- **Campaign → Audience:** Strict 1:1 at execution. A campaign targets exactly one audience (`list_id`).
- **Campaign → Segment:** Optional 0..1 : 1. A campaign targets either an entire audience or exactly one saved segment (`segment_id`).
- **Campaign → Target Link:** Strict 1:N. Every distinct destination URL or button within a campaign receives an ephemeral `target_link_id` unique to that specific campaign report.
- **Campaign → Folder:** Optional 0..1 : 1. Folders organize campaigns, never audiences.
- **Audience → Email Contact:** Strict 1:N. Contacts are strictly scoped to an audience. The same email address in two separate audiences represents two distinct contact records with independent statuses, ratings, and subscription states.
- **Store → Canonical Store:** Mailchimp registers a connected store (`store_id`) which resolves via domain equivalence to an agnostic `Store` entity in APIs Hub (`canonical_id: shop_domain`).

---

## 4. Agnostic Authentication & Token Lifecycle Architecture

In alignment with APIs Hub's strict architectural agnosticism, the orchestrator and Facade do not hardcode provider-specific authentication flows. Instead, they interact via contracts defined in `api-driver-core`.

### 4.1. Core Interfaces: `MultiAccountAuthProviderInterface`
Drivers supporting multiple account credentials per project (Mailchimp, Klaviyo, Shopify Private App tokens) implement `MultiAccountAuthProviderInterface` (extending `AuthProviderInterface`):
- `getAccounts(): array` — Returns all configured account identifiers and status descriptors.
- `getCredentialsForAccount(string $accountId): ?array` — Fetches credentials for an account.
- `storeAccountCredentials(string $accountId, array $credentials): void` — Adds/updates an account.
- `removeAccountCredentials(string $accountId): void` — Unlinks an account.

### 4.2. Storage Representation (`storage/tokens/mailchimp_tokens.json`)
The token storage file is managed uniformly via `BaseAuthProvider`, using an account-keyed structure:
```json
{
  "accounts": {
    "acc_us4_12345": {
      "account_id": "acc_us4_12345",
      "account_name": "Client A Brand",
      "api_key": "md5_secret_key-us4",
      "server_prefix": "us4",
      "created_at": "2026-09-26 12:00:00",
      "updated_at": "2026-09-26 12:00:00"
    }
  }
}
```

### 4.3. Preferred Authentication Method: API Key (Primary) & OAuth 2.0 (Secondary)
- **Primary / Recommended:** Standard API Key (`<hash>-<dc>`).
  - Auto-extracts datacenter/server prefix from the key suffix.
  - Long-lived and resilient; avoids unexpected OAuth token expiration during batch ingestion jobs.
- **Secondary:** OAuth 2.0 Authorization Code flow for partners.

### 4.4. Authentication Failure & `lost_access` Recovery Lifecycle
When Mailchimp returns HTTP `401 Unauthorized` or `403 Forbidden` (`API key invalid` or `user disabled`):
1. **SDK / Driver Classification:** `MailchimpErrorClassifier` categorizes the response as `fatal_auth` (`should_retry: false`).
2. **Account Suspension:** The driver halts sync attempts for *only that specific account*, while other healthy accounts continue executing.
3. **Facade Heartbeat Notification:** The remote node reports the failure to Facade (`MONITOR_FACADE_URL`), updating the account's state to `lost_access = true`.
4. **UI Alert & Recovery:** Facade displays an amber warning badge (`⚠️ Credentials Invalid / Expired`) with an in-place **"Update API Key"** modal, allowing instant re-authentication without data loss or pipeline interruption.

---

## 5. Ingestion Engine: Member Activity Retrieval (Point 2.A)

Mailchimp does **not** require querying one member at a time. The driver uses two high-throughput batch extraction pathways:

1. **Paginated Campaign Report Endpoints:**
   - `GET /reports/{campaign_id}/email-activity` (Returns paginated recipient actions: open, click, bounce).
   - `GET /reports/{campaign_id}/open-details?count=1000`
   - `GET /reports/{campaign_id}/click-details/{link_id}/members?count=1000`
2. **Export API (Streaming Large Volume Recipient Dumps):**
   - Mailchimp Export API (`/1.0/export`) outputs NDJSON (newline-delimited JSON) for massive recipient list and campaign event dumps without pagination overhead.

---

## 6. Cross-Domain Identity Resolution: Email vs. eCommerce

To prevent cartesian fan-out (1:N join inflation) and schema sparse pollution, **Email Contacts** and **eCommerce Customers** remain decoupled entity tables, unified analytically through a conformed natural identity key:

$$\text{identity\_hash} = \text{MD5}(\text{LOWER}(\text{TRIM}(\text{email\_address})))$$

### Domain Entity Separation

- **`dim_email_contact`:** Scoped to `(list_id, email_id)`. Tracks subscription status (`subscribed`, `unsubscribed`, `cleaned`), opt-in timestamps, and Mailchimp member engagement ratings.
- **`dim_ecommerce_customer`:** Scoped to `(store_id, customer_id)`. Tracks billing/shipping metadata, lifetime spend, order frequencies, and account state.
- **`channeled_orders` / `orders`:** Stored as independent transactional entities (amounts, items, currency, status, timestamp) carrying `identity_hash` and `store_id`. Mailchimp does not emit purchase event streams; attribution is resolved analytically by joining order entities to prior campaign click/open events via `identity_hash`.

---

## 7. Dimensional Matrix

| Dimension            | Scope / Origin    | Type          | Description                                                |
| :------------------- | :---------------- | :------------ | :--------------------------------------------------------- |
| `account_id`         | Core System       | Entity FK     | Tenant or organization boundary                            |
| `campaign_id`        | Mailchimp         | Entity FK     | The email delivery instance                                |
| `list_id`            | Mailchimp         | Entity FK     | Target audience identifier                                 |
| `segment_id`         | Mailchimp         | Entity FK     | Applied audience slice (nullable)                          |
| `folder_id`          | Mailchimp         | Entity FK     | Portfolio/folder grouping (nullable)                       |
| `template_id`        | Mailchimp         | Entity FK     | Structural layout design (nullable)                        |
| `target_link_id`     | Mailchimp         | Entity FK     | Specific URL/CTA button clicked (nullable)                 |
| `email_id`           | Mailchimp         | Entity FK     | Recipient contact MD5 identifier                           |
| `identity_hash`      | Derived           | Conformed Key | MD5 normalized email for cross-domain joins                |
| `store_id`           | eCommerce / MC    | Entity FK     | Canonical store identifier (`canonical_id: shop_domain`)   |
| `order_id`           | eCommerce / MC    | Entity FK     | Transactional purchase identifier                          |
| `product_id`         | eCommerce / MC    | Entity FK     | Purchased catalog item / SKU                               |
| `event_date`         | Ingestion         | Contextual    | Partition grain (`YYYY-MM-DD` UTC)                         |
| `event_hour`         | Ingestion         | Contextual    | Temporal distribution (`0-23` UTC)                         |
| `ip_address`         | Mailchimp         | Contextual    | Client IP on open/click                                    |
| `country_code`       | Mailchimp / GeoIP | Contextual    | ISO 2-letter geographical origin                           |
| `bounce_type`        | Mailchimp         | Contextual    | Delivery failure classification (`hard`, `soft`, `syntax`) |
| `unsubscribe_reason` | Mailchimp         | Contextual    | Churn feedback string                                      |
| `financial_status`   | eCommerce         | Contextual    | Transaction lifecycle (`paid`, `refunded`, `cancelled`)    |

---

## 8. Metric Taxonomy & Uniqueness Rules

Metrics are divided into strictly additive volumes and non-additive distinct counts. Rates are not persisted as raw aggregates; they are derived at query time.

### Core Metrics Definition

| Metric Key                 | Granularity Grain                               | Calculation / Ingestion Logic                     | Uniqueness & Additivity                                                                    |
| :------------------------- | :---------------------------------------------- | :------------------------------------------------ | :----------------------------------------------------------------------------------------- |
| `sends`                    | `date, campaign_id`                             | Count of delivered records from `/sent-to`        | Additive across time and campaigns. Fixed cohort denominator.                              |
| `opens_standard`           | `date, campaign_id`                             | Traditional human tracking pixel opens            | Additive across time and campaigns.                                                        |
| `opens_proxy`              | `date, campaign_id`                             | Apple Privacy / Bot pre-fetched opens             | Additive across time and campaigns. Separate metric for clean rate reporting.              |
| `opens_total`              | `date, campaign_id`                             | `opens_standard + opens_proxy`                    | Additive across time and campaigns.                                                        |
| `opens_unique`             | `date, campaign_id`                             | Distinct recipients opening the email             | **Non-additive over time.** Requires `COUNT(DISTINCT email_id)` across aggregated windows. |
| `clicks_total`             | `date, campaign_id, target_link_id`             | Sum of all CTA click occurrences                  | Additive across time, campaigns, and links.                                                |
| `clicks_unique`            | `date, campaign_id, target_link_id`             | Distinct recipients clicking a specific CTA       | **Non-additive over time.** Deduplicated per `(target_link_id, email_id)`.                 |
| `campaign_clickers_unique` | `date, campaign_id`                             | Distinct recipients clicking any CTA in the email | **Non-additive across links and time.** Deduplicated at `(campaign_id, email_id)`.         |
| `bounces_hard`             | `date, campaign_id`                             | Permanent failures (invalid address/domain)       | Additive (terminal state per recipient).                                                   |
| `bounces_soft`             | `date, campaign_id`                             | Temporary delivery failures                       | Additive per send attempt.                                                                 |
| `unsubscribes`             | `date, campaign_id`                             | Total opt-out actions from `/unsubscribed`        | Additive (terminal state per campaign).                                                    |
| `abuse_reports`            | `date, campaign_id`                             | Spam complaints logged from `/abuse-reports`      | Additive (terminal state per campaign).                                                    |
| `orders_count`             | `date, campaign_id, [target_link_id]`           | Attributed eCommerce orders                       | Additive (derived via attribution join).                                                   |
| `revenue`                  | `date, campaign_id, [target_link_id], currency` | Attributed monetary transaction values            | Additive (derived via attribution join).                                                   |

### Query-Time Derived Ratios

- **Delivery Rate:** $(\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft})) / \text{sends}$
- **Standard Open Rate (Adjusted):** $\text{opens\_standard} / (\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft}))$
- **Gross Open Rate:** $\text{opens\_total} / (\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft}))$
- **Link Click Rate:** $\text{clicks\_unique} / (\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft}))$
- **Campaign Click-Through Rate (CTR):** $\text{campaign\_clickers\_unique} / (\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft}))$
- **Click-to-Open Rate (CTOR):** $\text{campaign\_clickers\_unique} / \text{opens\_unique}$
- **Unsubscribe Rate:** $\text{unsubscribes} / (\text{sends} - (\text{bounces\_hard} + \text{bounces\_soft}))$
- **Average Order Value (AOV):** $\text{revenue} / \text{orders\_count}$

---

## 9. Transitive Attribution Architecture

Conversion causality is resolved in the **Agnostic Pre-Aggregation Engine** by linking raw email engagement to purchases:

```
[fact_campaign_events] (action: click, target_link_id: lnk_123, timestamp: T1)
         │
         │ Matches on identity_hash
         ▼
[fact_store_orders]     (order_id: ord_999, order_total: $120.00, timestamp: T2)
         │
         ▼
[Attributed Metric Result]
Grain: (campaign_id, target_link_id, event_date)
Metric: revenue += $120.00, orders_count += 1
```

### Attribution Hierarchy

1. **Level 1 — Deterministic / CTA Lineage (Highest Precision):**
   - Matches URL query params (`mc_cid` = campaign ID, `mc_eid` = recipient ID) passed to the checkout session.
   - Order links deterministically to the exact `Campaign` and `TargetLink` (button/CTA).
2. **Level 2 — Transitive Identity Match (Customer Email Hash):**
   - Matches `order.identity_hash == event.identity_hash`.
   - Window: `event.event_timestamp <= order.order_timestamp <= (event.event_timestamp + 30 Days)`.
   - Attributed to the latest click (or fallback to latest open within 5 days with `target_link_id = NULL`).
3. **Level 3 — Transitive Cohort Attribution (Audience Level):**
   - When individual customer hashes are masked/unavailable, orders originating from the canonical store cohort during campaign run dates can be attributed at the campaign aggregate level based on audience membership.
