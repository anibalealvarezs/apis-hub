# Meta Ads baseline-only synchronization

## Source and existing implementation analysis

Meta Ads is a paid acquisition channel, complementing organic Meta and downstream
GA4/conversion reporting. The driver synchronizes account → campaign → ad set → ad
and linked creative entities. Account/campaign/ad set/ad levels, daily date windows,
configured history ranges and existing BASIC/KEY/FULL metric tiers remain intact.
Clicks, spend, impressions, reach, actions and conversion metrics retain their
existing canonical mappings, units and reducers. No new metric or hierarchy is added.

Inspection of the local SDK shows `FacebookGraphApi::getAdAccountInsights` treats
`metricBreakdown=[]` as a request without the `breakdowns` parameter. A null value
activates its legacy default breakdown. The driver currently declares its unbroken
pass with null and `getMetricsConfig` also substitutes age/gender on null, so the
baseline request can unintentionally request audience segmentation.

The driver always runs baseline plus optional independent demographic, placement,
or country passes. Facade currently requires one of those optional passes; hydration
and change detection also turn empty lists back into demographics. Independent
passes must retain distinct metric identities; baseline-only changes neither metric
identity nor aggregation semantics. Existing chunking, cancellation, retry limits,
worker concurrency and source-history availability remain unchanged.

## Implementation and acceptance

- Add the explicit `none` selection in Meta Ads Data Sources and the channel schema.
- Preserve it through form hydration, serialization, config updates and comparison.
  Explicit empty lists mean no optional passes; absent legacy settings retain their
  previous default. Do not silently re-enable demographics after choosing none.
- Baseline uses an explicit empty array through driver metric resolution to the SDK;
  it must override any configured legacy age/gender breakdown list.
- A none selection runs only baseline at each configured entity level. Explicit
  enabled passes continue to run independently alongside baseline.
- Show a translated temporary-availability warning: campaigns targeting people in
  the United States should select no breakdown to collect data. Do not discuss
  legal reasons or collect targeting information merely to decide whether to warn.
- Preserve existing user-initiated save/resync behavior; no migrations, cache wipes,
  production configuration changes or source calls are executed by this code task.
- Test exact SDK arguments, optional-pass isolation, explicit-empty overrides,
  settings round trips, UI choices/warning, and hidden inactive breakdown tabs.

Rollout requires publishing the corrected Meta driver and installing it in tenants
before relying on the new UI option. No Composer constraint/tag is changed here.
