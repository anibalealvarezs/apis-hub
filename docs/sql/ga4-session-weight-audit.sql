-- Read-only PostgreSQL audit. Supply date_start, date_end and comma-separated account_ids.
-- Example: psql ... -v date_start=2026-08-01 -v date_end=2026-10-29 -v account_ids=46,42,43,47,45,44 -f this-file.sql
-- Audits exact cached grain; no updates, resync or identity changes.
BEGIN READ ONLY;
SET LOCAL statement_timeout = '120s';
WITH weights AS (
 SELECT wc.channel, wc.account_id, wc.channeled_account_id, wc.campaign_id, wc.channeled_campaign_id, wc.channeled_ad_group_id, wc.channeled_ad_id, wc.creative_id, wc.event_id, wc.channeled_event_id, wc.page_id, wc.post_id, wc.product_id, wc.customer_id, wc.order_id, wc.query_id, wc.country_id, wc.device_id, wc.dimension_set_id, wc.location_id, wc.state_id, wc.city_id, wm.metric_date,
        count(*) AS observation_count, max(wm.value) AS weight_value
 FROM metrics wm JOIN metric_configs wc ON wc.id = wm.metric_config_id
 WHERE wc.channel = (SELECT id FROM channels WHERE name = 'google_analytics')
   AND wc.channeled_account_id = ANY(string_to_array(:'account_ids', ',')::bigint[])
   AND lower(wc.name) = 'sessions' AND lower(wc.period) = 'daily'
   AND wm.metric_date BETWEEN :'date_start'::date AND :'date_end'::date
 GROUP BY wc.channel, wc.account_id, wc.channeled_account_id, wc.campaign_id, wc.channeled_campaign_id, wc.channeled_ad_group_id, wc.channeled_ad_id, wc.creative_id, wc.event_id, wc.channeled_event_id, wc.page_id, wc.post_id, wc.product_id, wc.customer_id, wc.order_id, wc.query_id, wc.country_id, wc.device_id, wc.dimension_set_id, wc.location_id, wc.state_id, wc.city_id, wm.metric_date
)
SELECT m.metric_date, mc.channeled_account_id,
 count(*) AS duration_rows,
 count(*) FILTER (WHERE w.observation_count IS NULL) AS missing_weights,
 count(*) FILTER (WHERE w.observation_count > 1) AS duplicate_weights,
 count(*) FILTER (WHERE w.observation_count = 1 AND (w.weight_value IS NULL OR w.weight_value < 0)) AS invalid_weights,
 count(*) FILTER (WHERE w.observation_count = 1 AND w.weight_value = 0) AS zero_weights,
 count(*) FILTER (WHERE m.value IS NULL) AS null_duration_values,
 sum(w.weight_value) FILTER (WHERE w.observation_count = 1 AND w.weight_value >= 0) AS valid_weight_total,
 min(mc.id) FILTER (WHERE w.observation_count IS NULL OR w.observation_count <> 1 OR w.weight_value IS NULL OR w.weight_value < 0) AS example_problem_config
FROM metrics m JOIN metric_configs mc ON mc.id = m.metric_config_id
LEFT JOIN weights w ON w.metric_date = m.metric_date
 AND w.channel IS NOT DISTINCT FROM mc.channel
 AND w.account_id IS NOT DISTINCT FROM mc.account_id
 AND w.channeled_account_id IS NOT DISTINCT FROM mc.channeled_account_id
 AND w.campaign_id IS NOT DISTINCT FROM mc.campaign_id
 AND w.channeled_campaign_id IS NOT DISTINCT FROM mc.channeled_campaign_id
 AND w.channeled_ad_group_id IS NOT DISTINCT FROM mc.channeled_ad_group_id
 AND w.channeled_ad_id IS NOT DISTINCT FROM mc.channeled_ad_id
 AND w.creative_id IS NOT DISTINCT FROM mc.creative_id
 AND w.event_id IS NOT DISTINCT FROM mc.event_id
 AND w.channeled_event_id IS NOT DISTINCT FROM mc.channeled_event_id
 AND w.page_id IS NOT DISTINCT FROM mc.page_id
 AND w.post_id IS NOT DISTINCT FROM mc.post_id
 AND w.product_id IS NOT DISTINCT FROM mc.product_id
 AND w.customer_id IS NOT DISTINCT FROM mc.customer_id
 AND w.order_id IS NOT DISTINCT FROM mc.order_id
 AND w.query_id IS NOT DISTINCT FROM mc.query_id
 AND w.country_id IS NOT DISTINCT FROM mc.country_id
 AND w.device_id IS NOT DISTINCT FROM mc.device_id
 AND w.dimension_set_id IS NOT DISTINCT FROM mc.dimension_set_id
 AND w.location_id IS NOT DISTINCT FROM mc.location_id
 AND w.state_id IS NOT DISTINCT FROM mc.state_id
 AND w.city_id IS NOT DISTINCT FROM mc.city_id
WHERE mc.channel = (SELECT id FROM channels WHERE name = 'google_analytics')
 AND mc.channeled_account_id = ANY(string_to_array(:'account_ids', ',')::bigint[])
 AND lower(mc.name) = 'averagesessionduration' AND lower(mc.period) = 'daily'
 AND m.metric_date BETWEEN :'date_start'::date AND :'date_end'::date
 AND EXISTS (
   SELECT 1 FROM dimension_set_items i
   JOIN dimension_values v ON v.id = i.dimension_value_id
   JOIN dimension_keys k ON k.id = v.dimension_key_id
   WHERE i.dimension_set_id = mc.dimension_set_id
     AND lower(k.name) = 'scope' AND v.value = 'traffic_matrix'
 )
GROUP BY m.metric_date, mc.channeled_account_id
ORDER BY m.metric_date, mc.channeled_account_id;
ROLLBACK;
