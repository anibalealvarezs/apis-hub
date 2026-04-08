# Phase 5: Comprehensive Decoupling Plan - Remaining Drivers

This plan outlines the final extraction of all channel-specific drivers from the `apis-hub` host into modular, external packages.

## 1. Objectives
- **Zero-Local-Drivers**: Move all remaining directories in `src/Channels/` to specialized hub-driver packages.
- **Orchestration Standardization**: Apply the "Meta Pattern" (Driver-led sync + DateHelper) to all channels.
- **SDK Resilience**: Move retry and fallback logic from the host to the respective SDKs.

## 2. Extraction Roadmap

### 2.1 Google Extraction (`google-hub-driver`)
- **Drivers**: `SearchConsoleDriver`, `GoogleAnalyticsDriver`.
- **Target Package**: `anibalealvarezs/google-hub-driver`.
- **Key Tasks**:
    - Migrate Auth logic to `GoogleAuthProvider` in the new package.
    - Implement date-chunking loops in drivers using `DateHelper`.
    - Clean up `MetricRequests.php` for GSC and GA.

### 2.2 Individual Provider Extractions
- **Shopify**: `anibalealvarezs/shopify-hub-driver`
- **BigCommerce**: `anibalealvarezs/bigcommerce-hub-driver`
- **Klaviyo**: `anibalealvarezs/klaviyo-hub-driver`
- **TikTok**: `anibalealvarezs/tiktok-hub-driver`
- **LinkedIn**: `anibalealvarezs/linkedin-hub-driver`
- **Pinterest**: `anibalealvarezs/pinterest-hub-driver`
- **NetSuite**: `anibalealvarezs/netsuite-hub-driver`
- **Amazon**: `anibalealvarezs/amazon-api` (Wait, Amazon might already be integrated or needs a hub-driver)
- **X**: `anibalealvarezs/x-hub-driver`
- **TripleWhale**: `anibalealvarezs/triple-whale-hub-driver`

**Key Tasks per Package**:
- Port the current local driver from `src/Channels/{Provider}`.
- Migrate the specific `AuthProvider` for that provider.
- Implement specialized orchestration (e.g., entity types for E-commerce).

## 3. Host Cleanup Strategy (`apis-hub`)
As drivers are moved:
1.  **Remove Files**: Delete `src/Channels/{ChannelName}`.
2.  **Update Factory**: Update `DriverFactory.php` to use the new class names from external packages.
3.  **Slim down Request Classes**: Ensure `MetricRequests`, `OrderRequests`, etc., only contain the `return (new SyncService())->execute(...)` proxy call.
4.  **Verification**: 
    - Run unit tests after each extraction.
    - Verify `composer.json` path mapping.

## 4. Immediate Next Step
- **Target**: **Google Extraction (GSC/GA)**.
- **Action**: Create `google-hub-driver` repository structure and move `SearchConsoleDriver`.

---
*Status: Planned | Next: Task 2.1 Initialization*
