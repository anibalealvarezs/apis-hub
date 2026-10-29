# GSC search type expansion

GSC measures organic acquisition and complements paid Google Ads and downstream GA4 traffic. Existing assets remain sites/accounts and page/query/geo/device entities; no new entity hierarchy is required.

## Source findings

- Search Analytics uses one endpoint, with a separate `type` request for `web`, `image`, `video`, `news`, `discover`, and `googleNews`. Default is Web. The SDK already forwards `type` through pagination.
- Web/Image/Video/News support query, page, country and device. Discover/Google News use a conservative date/page/country extraction and only clicks, impressions and CTR: do not invent positions or queries. Appearance breakdown remains a separate overlapping dataset for search-result types.
- Daily data uses Pacific dates. Source history is limited (normally 16 months), rows are top/truncated data, maximum page size is 25,000; retrieval cannot recover privacy-suppressed query data. Existing bounded SDK pagination and driver retry policy remain in use. Cancellation is checked between days, search types and passes.
- Existing synthetic reconciliation must run separately per type. Fetch all types sequentially, keep worker defaults, and allow configured `search_types` to reduce quota usage. Do not fetch 16 reconciliation subsets for a type/day with no baseline data.
- Canonical clicks/impressions remain counts; CTR is a ratio and position is impression-weighted. No new currency, conversion metric or statistical KPI is introduced. Derived URL/query attributes are outside this change.

## Implementation and acceptance

- [x] Driver passes explicit type, annotates rows, retains it in converted dimensions and signatures; missing type on legacy input defaults to Web.
- [x] Supported type list is validated; cancellation and failures cannot be marked as successful complete coverage.
- [x] Driver profiles default existing queries to Web, permit explicit type filters/grouping, and do not mix appearance datasets.
- [x] Tenant forward upgrade adds Web only to untyped GSC dimension sets, updates config signatures and metric hashes atomically, and preserves typed/other-channel data. Duplicate conflicts fail safely rather than sum observations.
- [x] Historical additional-type coverage requires a non-destructive GSC replay of the available cache range; deployment instructions are in the driver's surface manifest. No global nuclear resync/data wipe.
- [x] Tests cover type isolation, feed compatibility, missing metrics, default conversion, signatures, cancellation, and upgrade idempotence/rollback.

References: https://developers.google.com/webmaster-tools/v1/searchanalytics/query ; https://developers.google.com/search/blog/2021/10/search-analytics-discover-gnews ; https://support.google.com/webmasters/answer/9216516 ; https://support.google.com/webmasters/answer/10083653
