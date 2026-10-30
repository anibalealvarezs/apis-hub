# GA4 correctness implementation analysis

Date: 2026-10-30. Scope: correctness repairs to the existing GA4 driver and consumers.
Inputs: Facade analytics availability audit and progressive remediation plan.

GA4 measures acquisition, engagement and revenue for properties; it is not an ad-cost
source. Current daily account, traffic, acquisition, event and ad-touchpoint reports
are overlapping datasets. User counts are preaggregated distincts, not atomic users.
Traffic rates require session weights; they cannot be summed or averaged uniformly.
Names/manual terms are reporting labels, not stable ad-platform entity IDs.

Existing SDK exposes metadata and paginated runReport callbacks. Compatibility is
property-specific. Source references checked: Google Data API schema and
properties.checkCompatibility. No authenticated provider calls are authorized by the
offline implementation workflow. Exact window uniques cannot be reconstructed from
daily/slice counts; expose their sum explicitly until a dedicated window-report cache
is designed, budgeted and reconciled. Do not ingest user-level identities for this.

Implementation topology: driver declares query policy in its aggregation profile;
core preserves that optional contract; tenant validates it before all execution paths
and handles its generic weighted/derived-dimension rules. Facade aligns labels,
availability and errors. No channel-specific SQL policy belongs in the tenant engine.
Existing provider dimensions/signatures must remain stable; virtual source/medium
composition avoids a historical grain migration. Removing revenue-as-spend is a
read-time correction and does not require rewriting observations.

Ingestion: retain raw names, units and source quality metadata. Choose supported
key-event names through property metadata without storing both aliases as separate
observations. Validate requested metric/dimension compatibility before reporting;
failed matrices must fail the sync job rather than claim complete success.
Do not increase source worker concurrency or bypass existing quota/backpressure.

History: rates can use existing same-grain session companions when present; absent
weights must produce unavailable rather than a fabricated average. Daily user sums
remain explicitly non-unique. Old quality metadata cannot be reconstructed without
refetching. No automatic wipe, source replay or release-number allocation is part of
this change. Release SDK/core before driver and tenant before exposing dependent UI;
test a representative PostgreSQL copy and controlled source reconciliation at rollout.

Validation: synthetic conversion/metadata fixtures; generic contract and weighted SQL
tests with unequal weights/missing companions; source/medium filter compatibility;
scope isolation; cost rejection; Explorer/widget availability and existing GSC guards.
Operational acceptance remains separate from passing offline tests.
