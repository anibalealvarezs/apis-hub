# Mailchimp API SDK Upgrade Implementation Plan

**Repository:** `d:\laragon\www\mailchimp-api-anibal`  
**Package:** `anibalealvarezs/mailchimp-api`  
**Target Consumer:** `mailchimp-hub-driver` & `apis-hub`

---

## 1. Executive Summary & Goals

The `mailchimp-api-anibal` SDK currently supports basic list management and campaign fetching, but lacks the endpoints required for **analytics ingestion, atomic event pre-aggregation, and eCommerce attribution**.

This upgrade will equip the SDK with:
1. **High-Throughput Campaign Reporting:** Full batch extraction of opens (including Apple MPP flags), clicks, and recipient activity logs.
2. **CTA-Level Link Hierarchy:** Tracking of specific campaign links/buttons for deterministic conversion lineage.
3. **Connected eCommerce Integration:** Ingestion of connected stores, customer profiles, and transactional orders.
4. **Resilient Pagination Engine:** Fix hardcoded `$count = 1000` pagination bugs in `getAll*AndProcess()` methods.
5. **Multi-Authentication Support:** Seamless header construction supporting both HTTP Basic (`apiKey`) and HTTP Bearer (`oauth_token`).

---

## 2. API Surface Extensions (`MarketingApi.php`)

### 2.1. Authentication Header Refactoring
Currently `MarketingApi` hardcodes HTTP Basic authentication via `BasicClient`:
- Extend constructor to accept either `apiKey` OR `accessToken` (OAuth).
- If `accessToken` is provided, set `Authorization: Bearer <token>`.
- If `apiKey` is provided, set `Authorization: Basic base64(any:<apiKey>)`.

### 2.2. Campaign Reports & Member Activity Streams

```php
/**
 * GET /reports/{campaign_id}/email-activity
 * Ingests raw recipient event stream (opens, clicks, bounces) with timestamps.
 */
public function getEmailActivity(
    string $campaignId,
    int $count = 1000,
    int $offset = 0,
    ?string $since = null
): array;

public function getAllEmailActivityAndProcess(
    string $campaignId,
    callable $callback,
    int $batchSize = 1000,
    ?string $since = null
): void;

/**
 * GET /reports/{campaign_id}/open-details
 * Includes Apple MPP proxy open flags (members.opens[].proxy_open).
 */
public function getOpenDetails(
    string $campaignId,
    int $count = 1000,
    int $offset = 0,
    ?string $since = null
): array;

public function getAllOpenDetailsAndProcess(
    string $campaignId,
    callable $callback,
    int $batchSize = 1000
): void;

/**
 * GET /reports/{campaign_id}/click-details
 * Lists all tracked links/buttons inside a campaign with unique and total clicks.
 */
public function getClickDetails(string $campaignId): array;

/**
 * GET /reports/{campaign_id}/click-details/{link_id}/members
 * Retrieves the specific recipients who clicked a specific CTA.
 */
public function getClickMembers(
    string $campaignId,
    string $linkId,
    int $count = 1000,
    int $offset = 0
): array;

public function getAllClickMembersAndProcess(
    string $campaignId,
    string $linkId,
    callable $callback,
    int $batchSize = 1000
): void;

/**
 * GET /reports/{campaign_id}/sent-to
 * Full delivery log per recipient (denominator for delivery rate).
 */
public function getSentToMembers(
    string $campaignId,
    int $count = 1000,
    int $offset = 0
): array;
```

---

### 2.3. eCommerce Stores, Orders & Customers

Mailchimp connected stores link external eCommerce platforms (Shopify, WooCommerce, Custom) to Mailchimp marketing campaigns:

```php
/**
 * GET /ecommerce/stores
 * Discovers connected stores linked to this Mailchimp account.
 */
public function getEcommerceStores(int $count = 100, int $offset = 0): array;

/**
 * GET /ecommerce/stores/{store_id}/orders
 * Extracts eCommerce orders for transitive attribution.
 */
public function getEcommerceOrders(
    string $storeId,
    int $count = 1000,
    int $offset = 0,
    ?string $customerEmail = null,
    ?string $campaignId = null
): array;

public function getAllEcommerceOrdersAndProcess(
    string $storeId,
    callable $callback,
    int $batchSize = 1000,
    ?string $since = null
): void;

/**
 * GET /ecommerce/stores/{store_id}/customers
 */
public function getEcommerceCustomers(
    string $storeId,
    int $count = 1000,
    int $offset = 0
): array;
```

---

### 2.4. Organizational Taxonomy (Folders & Templates)

```php
/**
 * GET /campaign-folders
 */
public function getCampaignFolders(int $count = 100, int $offset = 0): array;

/**
 * GET /templates
 */
public function getTemplates(
    int $count = 1000,
    int $offset = 0,
    string $type = 'user'
): array;
```

---

## 3. Bug Fixes & Refactoring in Existing Code

### 3.1. Configurable Batch Sizes in Loops
In methods like `getAllCampaignsAndProcess()` and `getAllListMembersInfoAndProcess()`:
- **Problem:** `$count = 1000` is hardcoded. When mocking pagination in tests with smaller page sizes (e.g. 1 or 2 items), `$offset += 1000` causes loops to break immediately, causing test failures.
- **Fix:** Add `$batchSize = 1000` parameter and use `$offset += $batchSize`.

### 3.2. Error Classifier Validation for 401 & 403
Verify that `MailchimpErrorClassifier.php` properly distinguishes:
- `401 Unauthorized` / `403 Forbidden` $\to$ `category: fatal_auth`, `should_retry: false`.
- `429 Too Many Requests` $\to$ `category: retryable`, `should_retry: true`.

---

## 4. Testing & Validation Strategy

1. **Unit Tests with Mock Guzzle Handlers:**
   - Test `getEmailActivity()` parsing and pagination.
   - Test Apple MPP proxy open extraction in `getOpenDetails()`.
   - Test CTA member resolution in `getClickMembers()`.
   - Test eCommerce orders extraction and campaign referral ID preservation.
   - Test both Basic Auth (API Key) and Bearer Auth (OAuth) request header generation.
2. **PHPUnit Execution:**
   - Ensure all 9 existing tests pass (fixing the 2 existing pagination failures) and new endpoint tests achieve 100% assertion success.

---

## 5. Execution Roadmap

| Step | Action Item | Target Location |
| :--- | :--- | :--- |
| **1** | Fix pagination batch parameter in existing methods | `src/Services/Marketing/MarketingApi.php` |
| **2** | Add Campaign Reporting endpoints (activity, opens, clicks) | `src/Services/Marketing/MarketingApi.php` |
| **3** | Add eCommerce endpoints (stores, orders, customers) | `src/Services/Marketing/MarketingApi.php` |
| **4** | Add Organizational endpoints (folders, templates) | `src/Services/Marketing/MarketingApi.php` |
| **5** | Update & Expand Unit Test Suite | `tests/Services/Marketing/MarketingApiTest.php` |
| **6** | Run PHPUnit & Ensure 100% Pass Rate | `mailchimp-api-anibal` |
