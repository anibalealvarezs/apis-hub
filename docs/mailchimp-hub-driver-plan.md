# Mailchimp Hub Driver Implementation Plan (PLAN-04)

**Repository Path:** `d:\laragon\www\mailchimp-hub-driver`  
**Composer Package:** `anibalealvarezs/mailchimp-hub-driver`  
**Master Plan Reference:** [`docs/v1.17.0-master-implementation-plan.md`](file:///d:/laragon/www/apis-hub/docs/v1.17.0-master-implementation-plan.md) (Phase 3)  
**Parent Layer:** Normalization (Drivers)

---

## 1. Executive Summary & Driver Role

The `mailchimp-hub-driver` connects the `apis-hub` orchestrator to the `mailchimp-api-anibal` SDK. Its responsibilities are:
1. **Entity Synchronization:** Caches high-level static containers (Audiences, Connected Stores, Campaigns, Folders, Templates, Links) via `syncEntities()`.
2. **Atomic Event & Order Normalization:** Ingests recipient activity streams and store orders, standardizing them into `UniversalEntity` objects for persistence in `channeled_events` and `channeled_orders`.
3. **Pre-Aggregation Contract Fulfillment:** Implements `PreAggregationProviderInterface` so `AgnosticPreAggregationEngine` knows how to slice Mailchimp event records into 1-day metric grains.
4. **Multi-Account Credential Management:** Implements `MultiAccountAuthProviderInterface` to authenticate and sync $N$ distinct Mailchimp accounts within a single project.

---

## 2. Canonical Standardization: Google & Meta Driver Parity

To ensure architectural uniformity across APIs Hub, `d:\laragon\www\mailchimp-hub-driver` strictly adheres to the established patterns of `google-hub-driver` and `meta-hub-driver`:

### 2.1. Composer Configuration (`composer.json`)
- Mirrors `google-hub-driver` and `meta-hub-driver` requirements:
  - `"php": ">=8.3"` with platform override `"php": "8.3.0"`
  - `"anibalealvarezs/api-client-skeleton": "^1.0.0"`
  - `"anibalealvarezs/api-driver-core": "^1.1.0 || ^1.2.0"`
  - `"anibalealvarezs/mailchimp-api": "1.1.0"` (Locked to release version)
  - `"symfony/http-foundation": "^6.0 || ^7.0"`
  - `"doctrine/orm": "^2.12.3 || ^3.0"`
  - `"nesbot/carbon": "^2.0 || ^3.0"`
  - `"psr/log": "^1.0 || ^2.0 || ^3.0"`
  - Satis repository linkage: `"https://satis.anibalalvarez.com/"` with symlinked path repositories for local development.

### 2.2. Files Ignoring (`.gitignore`)
Matches the canonical driver `.gitignore`:
```gitignore
vendor
.idea
.vscode
.phpunit.result.cache
*.log
**/.php-cs-fixer.dist.php

.copilot/composer-path-repositories.backup.json
```

### 2.3. Agent & Memory Files
- **`AGENTS.md`**: Defines role as Normalization (Drivers), parent context pointing to `D:\laragon\www\_shared\AGENTS.md`, and dependencies stance (`anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, and `anibalealvarezs/mailchimp-api`; serving `apis-hub`).
- **`MEMORY.md`**: Local repository-specific memory documenting channel rules, multi-account token schemas, and pre-aggregation contracts.

---

## 3. Relational Entity Architecture & Persistence

APIs Hub persists two tiers of entities for Mailchimp: **Hierarchical Master Objects** and **Atomic Event/Transactional Objects**.

### 3.1. Complete Entity Matrix

| Entity Type | DB Table | Category | Natural / Platform Key | Relational Purpose & Foreign Keys |
| :--- | :--- | :--- | :--- | :--- |
| **Account** | `channeled_accounts` | `IDENTITY` | `acc_{dc}_{user_id}` | Represents the Mailchimp account connection. Scopes all child assets. |
| **Audience** | `channeled_audiences` | `RESOURCE` / `IDENTITY` | `list_id` | Master subscriber pool. Tracked asset unit for billing quota. |
| **Connected Store** | `channeled_stores` | `RESOURCE` | `store_id` | Links to canonical `stores` via domain matching (`shop_domain`). Resolves external eCommerce integration. |
| **Campaign** | `channeled_campaigns` | `CAMPAIGN` | `campaign_id` | Delivery dispatch container. Carries subject line, send time, and links to `list_id` and optional `folder_id`. |
| **Target Link (CTA)** | `channeled_links` | `UNIT` | `link_id` | Distinct URL or button destination within a campaign. Scoped 1:N to `campaign_id`. Enables CTA-level conversion lineage. |
| **Folder** | `channeled_folders` | `GROUPING` | `folder_id` | Organizational taxonomy container grouping campaigns. |
| **Template** | `channeled_templates` | `RESOURCE` | `template_id` | Layout design template used across campaigns. |
| **Raw Event** | `channeled_events` | `ATOMIC` | `hash(camp, email, act, time)` | Recipient interaction record (`open`, `click`, `bounce`). Carries `identity_hash` and `target_link_id`. |
| **Store Order** | `channeled_orders` | `ATOMIC` | `order_id` | Transactional purchase. Carries `store_id`, `identity_hash`, `total_amount`, and campaign tracking params. |

---

## 4. Dual Worker Job Synchronization Topology

To maintain foreign key integrity, optimize memory, and enable idempotent recovery, Mailchimp synchronization is strictly split into **two distinct worker jobs**:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        APIs Hub Job Architecture                       │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│ 1. Entities Sync Job (`entities-sync`)                                 │
│    Frequency: Daily / On-Demand (Runs First)                          │
│    Method Invoked: `SyncDriverInterface::syncEntities()`               │
│    Payload / Task:                                                     │
│    • Iterates through active Mailchimp accounts                        │
│    • Fetches & caches static master objects:                           │
│      - Audiences (`getAllListsInfo()`)                                 │
│      - Connected Stores (`getAllEcommerceStores()`)                    │
│      - Folders (`getAllCampaignFolders()`)                             │
│      - Templates (`getAllTemplates()`)                                 │
│      - Campaigns (`getAllCampaigns()`)                                 │
│      - Tracked Links (`getAllClickDetails()`)                          │
│    • Guarantee: All parent relational entities and FK targets exist in │
│      the DB before any high-frequency event stream is ingested.        │
│                                                                        │
│ 2. Metric / Historic Ingestion Job (`mailchimp-YYYY-Q` or `recent`)    │
│    Frequency: Daily / Rolling History Windows                          │
│    Method Invoked: `SyncDriverInterface::sync($start, $end)`           │
│    Payload / Task:                                                     │
│    • Ingests date-bounded recipient activity streams:                  │
│      - `getAllEmailActivityAndProcess()`                               │
│      - `getAllOpenDetailsAndProcess()`                                 │
│      - `getAllEcommerceOrdersAndProcess()`                             │
│    • Streams normalized records into `channeled_events` and            │
│      `channeled_orders` with conformed `identity_hash`.                │
│    • Invokes `AgnosticPreAggregationEngine::rollup()`:                 │
│      - Reduces daily events into base metrics (`sends`, `opens`, etc.) │
│      - Executes 30-day transitive attribution joining email clicks to  │
│        store purchases to calculate `orders_count` and `revenue`.      │
│    • Persists final daily aggregations into `metrics` table.           │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 5. Class Architecture

```
d:\laragon\www\mailchimp-driver\src\
  ├── Auth/
  │     └── MailchimpAuthProvider.php       # Implements MultiAccountAuthProviderInterface
  ├── Conversions/
  │     └── MailchimpConvert.php            # UniversalEntityConverter mappings
  ├── Drivers/
  │     └── MailchimpDriver.php             # Implements SyncDriverInterface, PreAggregationProviderInterface
  └── Traits/
        └── MailchimpSyncDriverTrait.php
```

---

## 6. Multi-Account Authentication Implementation

### 6.1. `MailchimpAuthProvider`
Extends `BaseAuthProvider` and implements `MultiAccountAuthProviderInterface`:
- Reads/writes to `storage/tokens/mailchimp_tokens.json`.
- Schema:
  ```json
  {
    "accounts": {
      "acc_us4_client_a": {
        "account_id": "acc_us4_client_a",
        "account_name": "Client A Store",
        "api_key": "md5string-us4",
        "server_prefix": "us4",
        "created_at": "2026-09-26 12:00:00",
        "updated_at": "2026-09-26 12:00:00"
      }
    }
  }
  ```
- Methods:
  - `getAccounts(): array`
  - `getCredentialsForAccount(string $accountId): ?array`
  - `storeAccountCredentials(string $accountId, array $credentials): void`
  - `removeAccountCredentials(string $accountId): void`
  - `validateAuthentication(): array` (Pings each account's endpoint, returning per-account status)

---

## 7. Entity Normalization Mappings (`MailchimpConvert.php`)

Uses `UniversalEntityConverter` to transform raw API responses into APIs Hub standard objects:

1. **Audiences (Lists):**
   - Mapped to `AssetCategory::IDENTITY` / `RESOURCE`.
   - Fields: `platform_id = id`, `name = name`, `member_count = stats.member_count`.
2. **Connected Stores:**
   - Mapped to `ChanneledStore`.
   - Fields: `platform_id = id`, `name = name`, `domain = domain`, `channel = mailchimp`.
   - APIs Hub resolves `domain` to canonical `Store`.
3. **Campaigns:**
   - Mapped to `ChanneledCampaign`.
   - Fields: `platform_id = id`, `name = settings.title`, `subject = settings.subject_line`, `send_time = send_time`.
4. **Target Links (CTAs):**
   - Mapped to `ChanneledLink` under `AssetCategory::UNIT`.
   - Fields: `platform_id = link_id`, `url = url`, `campaign_id = campaign_id`.
5. **Raw Events:**
   - Mapped to `ChanneledEvent`.
   - Fields: `action = action` (`open`, `click`, `bounce`), `identity_hash = md5(lower(trim(email)))`, `target_link_id = link_id`, `timestamp = timestamp`.
6. **Orders:**
   - Mapped to `ChanneledOrder`.
   - Fields: `platform_id = id`, `store_id = store_id`, `total_amount = order_total`, `currency = currency_code`, `identity_hash = md5(lower(trim(customer.email_address)))`.

---

## 8. Pre-Aggregation Provider Rules (`MailchimpDriver.php`)

Implements `PreAggregationProviderInterface`:

```php
public static function getPreAggregationRules(): array
{
    return [
        'campaign_engagement' => [
            'source_entity' => 'channeled_events',
            'scope_field' => 'campaign_id',
            'metrics' => [
                'sends' => ['condition' => ['action' => 'send'], 'reducer' => 'count'],
                'opens_standard' => ['condition' => ['action' => 'open', 'is_proxy' => false], 'reducer' => 'count'],
                'opens_proxy' => ['condition' => ['action' => 'open', 'is_proxy' => true], 'reducer' => 'count'],
                'clicks_total' => ['condition' => ['action' => 'click'], 'reducer' => 'count'],
                'clicks_unique' => ['condition' => ['action' => 'click'], 'field' => 'email_id', 'reducer' => 'count_distinct'],
                'bounces_hard' => ['condition' => ['action' => 'bounce', 'type' => 'hard'], 'reducer' => 'count'],
                'bounces_soft' => ['condition' => ['action' => 'bounce', 'type' => 'soft'], 'reducer' => 'count'],
                'unsubscribes' => ['condition' => ['action' => 'unsubscribe'], 'reducer' => 'count'],
            ],
        ],
        'store_conversions' => [
            'source_entity' => 'channeled_orders',
            'scope_field' => 'store_id',
            'metrics' => [
                'orders_count' => ['field' => 'platform_id', 'reducer' => 'count_distinct'],
                'revenue' => ['field' => 'total_amount', 'reducer' => 'sum'],
            ],
        ],
    ];
}

public static function getDefaultAttributionWindowDays(): int
{
    return 30;
}
```

---

## 9. Testing & Quality Gates
1. Unit test `MailchimpAuthProvider` with multi-account storage mutations.
2. Unit test `MailchimpConvert` verifying exact property extraction and identity hash generation.
3. Integration test `MailchimpDriver::syncEntities()` verifying discovery of audiences, stores, campaigns, and links.
4. Integration test `MailchimpDriver::sync()` verifying batch execution and DataProcessor invocation.
