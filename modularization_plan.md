# Implementation Plan: Modularizing Request Methods in apis-hub

## Objective

Replace all remaining `getListFrom[Channel]` methods in `apis-hub` Request classes with a unified, driver-driven approach using `SyncService`.

## Target Files

The following files in `src/Classes/Requests/` contain legacy channel-specific methods:

- `DiscountRequests.php`
- `PriceRuleRequests.php`
- `ProductCategoryRequests.php` (**DONE**)
- `ProductVariantRequests.php`
- `VendorRequests.php`

## Phase 1: Driver Compliance (SDK/Driver Repos)

Ensure that all relevant drivers implement the necessary logic for the following entity types in their `sync()` method:

- `discounts`
- `price_rules`
- `product_categories`
- `product_variants`
- `vendors`

### Required Actions

1. Update `SyncDriverInterface` if any common helper is needed (already done in previous steps for date filters).
2. Implement entity-specific data retrieval and processing logic in the respective drivers (Shopify, BigCommerce, NetSuite, Amazon, Klaviyo).

## Phase 2: Refactoring apis-hub Request Classes

For each target file, perform the following:

### 1. VendorRequests.php

- **Status**: Currently has `getListFromShopify`, `getListFromBigCommerce`, `getListFromNetsuite`, `getListFromAmazon`.
- **Action**:
  - Update `getList` to use `SyncService::execute` with `type => 'vendors'`.
  - Remove all `getListFrom[Channel]` methods.

### 2. ProductVariantRequests.php

- **Status**: Currently has `getListFromShopify`, `getListFromKlaviyo`, `getListFromBigCommerce`, `getListFromNetsuite`, `getListFromAmazon`.
- **Action**:
  - Update `getList` to use `SyncService::execute` with `type => 'product_variants'`.
  - Remove all `getListFrom[Channel]` methods.

### 3. ProductCategoryRequests.php

- **Status**: **Completed**.
- **Action**:
  - Updated `getList` to use `SyncService::execute` with `type => 'product_categories'`.
  - Removed all `getListFrom[Channel]` methods.

### 4. PriceRuleRequests.php

- **Status**: Contains complex legacy logic for Shopify `getListFromShopify`.
- **Action**:
  - **Crucial**: Move the Shopify-specific logic (ShopifyApi call and processing) into `ShopifyHubDriver`.
  - Update `getList` to use `SyncService::execute` with `type => 'price_rules'`.
  - Remove all `getListFrom[Channel]` methods.

### 5. DiscountRequests.php

- **Status**: Methods mostly return empty arrays except for Shopify which notes it's handled with Price Rules.
- **Action**:
  - Update `getList` to use `SyncService::execute` with `type => 'discounts'`.
  - Remove all `getListFrom[Channel]` methods.

## Phase 3: Cleanup and Verification

1. Remove any remaining `method_exists` or `self::$method` dynamic calls in Request classes.
2. Verify that all standard entities are now purely modular.
3. Perform a test sync for one of the refactored entities.
