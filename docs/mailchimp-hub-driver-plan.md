# Mailchimp Hub Driver Implementation Plan (PLAN-04)

**Repository:** `d:\laragon\www\mailchimp-hub-driver`  
**Package:** `anibalealvarezs/mailchimp-hub-driver`  
**Master Plan Reference:** [`docs/v1.17.0-master-implementation-plan.md`](file:///d:/laragon/www/apis-hub/docs/v1.17.0-master-implementation-plan.md) (Phase 3)  
**Parent Layer:** Normalization (Drivers)

---

## 1. Executive Summary & Driver Role

The `mailchimp-hub-driver` connects the `apis-hub` orchestrator to the `mailchimp-api-anibal` SDK. Its responsibilities are:
1. **Entity Synchronization:** Caches high-level static containers (Audiences, Connected Stores, Campaigns, Folders, Templates) via `syncEntities()`.
2. **Atomic Event & Order Normalization:** Ingests recipient activity streams and store orders, standardizing them into `UniversalEntity` objects for persistence in `channeled_events` and `channeled_orders`.
3. **Pre-Aggregation Contract Fulfillment:** Implements `PreAggregationProviderInterface` so `AgnosticPreAggregationEngine` knows how to slice Mailchimp event records into 1-day metric grains.
4. **Multi-Account Credential Management:** Implements `MultiAccountAuthProviderInterface` to authenticate and sync $N$ distinct Mailchimp accounts within a single project.

---

## 2. Package Topology & Dependencies

```json
{
  "name": "anibalealvarezs/mailchimp-hub-driver",
  "description": "Mailchimp driver for the APIs Hub ecosystem",
  "license": "MIT",
  "require": {
    "php": ">=8.3",
    "anibalealvarezs/api-client-skeleton": "^1.0.0",
    "anibalealvarezs/api-driver-core": "^1.0.0",
    "anibalealvarezs/mailchimp-api": "^0.1.0",
    "psr/log": "^1.0 || ^2.0 || ^3.0",
    "symfony/http-foundation": "^6.0 || ^7.0"
  },
  "autoload": {
    "psr-4": {
      "Anibalealvarezs\\MailchimpHubDriver\\": "src/"
    }
  }
}
```

---

## 3. Class Architecture

```
d:\laragon\www\mailchimp-hub-driver\src\
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

## 4. Multi-Account Authentication Implementation

### 4.1. `MailchimpAuthProvider`
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

## 5. Entity Normalization Mappings (`MailchimpConvert.php`)

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

---

## 6. Pre-Aggregation Provider Rules (`MailchimpDriver.php`)

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

## 7. Testing & Quality Gates
1. Unit test `MailchimpAuthProvider` with multi-account storage mutations.
2. Unit test `MailchimpConvert` verifying exact property extraction and identity hash generation.
3. Integration test `MailchimpDriver::sync()` verifying batch execution and DataProcessor invocation.
