# Handoff: Aggregation Refactor - Pilot Expansion & Telemetry Hardening (2026-05-05)

## Current Status
- **Phase 2 Expansion:** `FacebookMarketingDriver`, `KlaviyoDriver`, and `ShopifyDriver` now implement `AggregationProfileProviderInterface`.
- **Core Templates:** Added `flowCampaignProfile` and `storeProfile` to `api-driver-core`.
- **Telemetry Hardening:** Official production path for telemetry events is now documented as `D:\laragon\www\apis-hub\storage\logs\aggregation-telemetry.jsonl`.

## Key Changes
- **Drivers:**
    - Modified `FacebookMarketingDriver.php` to declare ads aggregation capabilities.
    - Modified `KlaviyoDriver.php` to declare flow/campaign aggregation capabilities.
    - Modified `ShopifyDriver.php` to declare store performance aggregation capabilities.
- **Core:**
    - Updated `AggregationProfileTemplates.php` with new profile factories for Email and E-commerce.

## Pending Work
- **Monitoring:** Monitor the `aggregation-telemetry.jsonl` log in production environments to detect `missing_profile_capability` for Facebook Marketing.
- **Pilot Expansion:** Continue expanding pilot coverage to other drivers (e.g., Klaviyo, Shopify) following the established pattern.
- **Cleanup:** Progressively remove legacy SQL branches in `BaseRepository` as telemetry confirms coverage by optimized paths.

## Verification Done
- Verified that `FacebookMarketingDriver` implements the required interface.
- Verified that `AggregationProfileTemplates::adsHierarchyProfile` is available in `api-driver-core`.
- Verified that telemetry path defaults are consistent across documentation and configuration.
