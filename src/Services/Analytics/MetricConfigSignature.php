<?php

namespace Services\Analytics;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;

/** Shared canonical signature inputs for maintenance and upgrades. */
class MetricConfigSignature
{
    public static function selectSql(): string
    {
        return "
            SELECT
                mc.id,
                mc.config_signature,
                mc.channel,
                mc.name,
                mc.period,
                mc.account_id,
                a.name AS account_name,
                mc.channeled_account_id,
                ca.platform_id AS channeled_account_platform_id,
                mc.campaign_id,
                cm.campaign_id AS campaign_campaign_id,
                mc.channeled_campaign_id,
                cc.platform_id AS channeled_campaign_platform_id,
                mc.channeled_ad_group_id,
                cag.platform_id AS channeled_ad_group_platform_id,
                mc.channeled_ad_id,
                cad.platform_id AS channeled_ad_platform_id,
                mc.creative_id,
                cr.creative_id AS creative_creative_id,
                mc.page_id,
                p.url AS page_url,
                mc.query_id,
                q.query AS query_query,
                mc.post_id,
                po.post_id AS post_post_id,
                mc.product_id,
                pr.product_id AS product_product_id,
                mc.customer_id,
                cu.email AS customer_email,
                mc.order_id,
                o.order_id AS order_order_id,
                mc.country_id,
                co.code AS country_code,
                mc.device_id,
                d.type AS device_type,
                mc.dimension_set_id,
                ds.hash AS dimension_set_hash,
                mc.location_id,
                l.platform_id AS location_platform_id,
                mc.state_id,
                s.name AS state_name,
                mc.city_id,
                ci.name AS city_name,
                mc.event_id,
                e.name AS event_name,
                mc.channeled_event_id,
                ce.platform_id AS channeled_event_platform_id
            FROM metric_configs mc
            LEFT JOIN accounts a ON mc.account_id = a.id
            LEFT JOIN channeled_accounts ca ON mc.channeled_account_id = ca.id
            LEFT JOIN campaigns cm ON mc.campaign_id = cm.id
            LEFT JOIN channeled_campaigns cc ON mc.channeled_campaign_id = cc.id
            LEFT JOIN channeled_ad_groups cag ON mc.channeled_ad_group_id = cag.id
            LEFT JOIN channeled_ads cad ON mc.channeled_ad_id = cad.id
            LEFT JOIN creatives cr ON mc.creative_id = cr.id
            LEFT JOIN pages p ON mc.page_id = p.id
            LEFT JOIN queries q ON mc.query_id = q.id
            LEFT JOIN posts po ON mc.post_id = po.id
            LEFT JOIN products pr ON mc.product_id = pr.id
            LEFT JOIN customers cu ON mc.customer_id = cu.id
            LEFT JOIN orders o ON mc.order_id = o.id
            LEFT JOIN countries co ON mc.country_id = co.id
            LEFT JOIN devices d ON mc.device_id = d.id
            LEFT JOIN dimension_sets ds ON mc.dimension_set_id = ds.id
            LEFT JOIN locations l ON mc.location_id = l.id
            LEFT JOIN states s ON mc.state_id = s.id
            LEFT JOIN cities ci ON mc.city_id = ci.id
            LEFT JOIN events e ON mc.event_id = e.id
            LEFT JOIN channeled_events ce ON mc.channeled_event_id = ce.id
            ORDER BY mc.id ASC
        ";
    }

    public static function fromRow(array $row): string
    {
        return KeyGenerator::generateMetricConfigKey(
            channel: (int) $row['channel'],
            name: (string) $row['name'],
            period: $row['period'],
            account: $row['account_name'],
            channeledAccount: $row['channeled_account_platform_id'],
            campaign: $row['campaign_campaign_id'],
            channeledCampaign: $row['channeled_campaign_platform_id'],
            channeledAdGroup: $row['channeled_ad_group_platform_id'],
            channeledAd: $row['channeled_ad_platform_id'],
            creative: $row['creative_creative_id'],
            page: $row['page_url'],
            query: $row['query_query'],
            post: $row['post_post_id'],
            product: $row['product_product_id'],
            customer: $row['customer_email'],
            order: $row['order_order_id'],
            country: $row['country_code'],
            device: $row['device_type'],
            dimensionSet: $row['dimension_set_hash'],
            location: $row['location_platform_id'],
            state: $row['state_name'],
            city: $row['city_name'],
            event: $row['event_name'],
            channeledEvent: $row['channeled_event_platform_id']
        );
    }

}
