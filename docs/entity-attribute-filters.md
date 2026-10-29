# Entity attribute filters

Metric aggregations support channel-independent paths rooted at `MetricConfig`:

```json
{
  "aggregations": {"spend": "spend", "clicks": "clicks"},
  "groupBy": ["daily"],
  "filters": {
    "channel": "facebook_marketing",
    "channeledAccount": 42,
    "attributes.channeledCampaign.objective": {
      "operator": "in", "value": ["OUTCOME_SALES", "OUTCOME_LEADS"]
    },
    "attributes.channeledCampaign.buyingType": {
      "operator": "eq", "value": "AUCTION"
    },
    "attributes.channeledCampaign.budget": {
      "operator": "between", "value": [1000, 50000]
    }
  }
}
```

Paths use **Doctrine property names**, such as `buyingType`, rather than physical column names. Owning, singular relationships can be chained, e.g. `attributes.channeledAd.channeledCampaign.objective`. Up to four relationship hops are accepted. Each relationship/field is validated against ORM metadata; SQL tables, columns and join conditions come exclusively from that metadata. Collection relationships and JSON/blob/object fields are rejected. The contract covers mapped scalar entity attributes; it does not reinterpret arbitrary provider JSON as typed columns.

No channel names or attribute lists are encoded in the engine. A new channel using the normalized model works automatically. Newly mapped scalar attributes or owning singular relationships become filterable without changing the resolver. A provider-only field must first be represented in the entity model.

Supported operators: `eq`, `neq`, `in`, `not_in`, `like`, `not_like`, `is_null`, `is_not_null`, `gt`, `gte`, `lt`, `lte`, `between`. Range symbols `>`, `>=`, `<`, `<=` are also accepted. Use numeric values in stored entity units; budgets are not converted to display currency units. Numeric columns are compared numerically, never cast to text. `between` includes both boundaries. Repeated predicates are ANDed using the existing suffix convention (`attributes.channeledCampaign.budget__1`). Values within an `in` predicate are ORed. Unknown paths/operators and invalid numeric bounds fail rather than silently changing semantics.

Attributes refer to the latest synced entity record, not a historical snapshot of entity settings on each metric date. Account/channel/date filters remain in effect. Joins use singular foreign keys, so attributes cannot fan out metric rows. Snapshot aggregates use the legacy snapshot pipeline with the same attribute predicate compiler; other attribute-filtered metric requests use the universal strategy.

## Discovery

`POST /{channel}/metric/attributes` accepts `{}` for metadata only. It returns a map keyed by filter path, including `relation`, `field`, `type`, `label`, and enum `options`. Direct relationships are listed; nested paths can be supplied in aggregate requests.

For facet values, send `{"relation":"channeledCampaign","account":["42"],"values":true}`. Account IDs are internal IDs. Values are scoped to that channel/account selection and collected from associated metric configurations across all dates. At most 200 values per field are returned with an explicit `truncated` flag. Numeric/date fields use boundary controls. Data Explorer consumes this endpoint through the authenticated facade, which applies collaborator asset access before forwarding the request. Widget metadata discovery returns schema only.

## Delivery

Release the tenant `apis-hub` changes and SDK `apis-hub-api` method before enabling the facade changes. No Composer linking, dependency manifest edits, database migrations, or tenant mutations are performed by this implementation.
