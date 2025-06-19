<?php

namespace Classes;

use DateTime;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Country;
use Entities\Analytics\Customer;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Order;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Product;
use Entities\Analytics\Query;
use Enums\Channel;
use Enums\Period;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;

class KeyGenerator
{
    public static function generateQueryKey(Query|string $query): string
    {
        return md5($query instanceof Query ? $query->getQuery() : $query);
    }

    /**
     * @param Channel|int|string $channel
     * @param string $name
     * @param Period|string $period
     * @param DateTime|string $metricDate
     * @param Account|int|null $account
     * @param ChanneledAccount|int|null $channeledAccount
     * @param Campaign|int|null $campaign
     * @param ChanneledCampaign|int|null $channeledCampaign
     * @param ChanneledAdGroup|int|null $channeledAdGroup
     * @param ChanneledAd|int|null $channeledAd
     * @param Page|string|null $page
     * @param Query|string|null $query
     * @param Post|int|null $post
     * @param Product|int|null $product
     * @param Customer|int|null $customer
     * @param Order|int|null $order
     * @param Country|CountryEnum|string|null $country
     * @param Device|DeviceEnum|string|null $device
     * @return string
     */
    public static function generateMetricKey(
        Channel|int|string $channel,
        string $name,
        Period|string $period,
        DateTime|string $metricDate,
        Account|int|null $account = null,
        ChanneledAccount|int|null $channeledAccount = null,
        Campaign|int|null $campaign = null,
        ChanneledCampaign|int|null $channeledCampaign = null,
        ChanneledAdGroup|int|null $channeledAdGroup = null,
        ChanneledAd|int|null $channeledAd = null,
        Page|string|null $page = null,
        Query|string|null $query = null,
        Post|int|null $post = null,
        Product|int|null $product = null,
        Customer|int|null $customer = null,
        Order|int|null $order = null,
        Country|CountryEnum|string|null $country = null,
        Device|DeviceEnum|string|null $device = null
    ): string
    {
        return match($channel) {
            Channel::google_search_console => md5(string: json_encode([
                'channel' => $channel instanceof Channel ? $channel->getName() : (is_numeric($channel) ? Channel::from($channel)->getName() : $channel),
                'name' => $name,
                'period' => $period instanceof Period ? $period->value : $period,
                'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
                'account' => $account instanceof Account ? $account->getId() : $account,
                'channeledAccount' => $channeledAccount instanceof ChanneledAccount ? $channeledAccount->getId() : $channeledAccount,
                'campaign' => $campaign instanceof Campaign ? $campaign->getId() : $campaign,
                'channeledCampaign' => $channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getId() : $channeledCampaign,
                'channeledAdGroup' => $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getId() : $channeledAdGroup,
                'channeledAd' => $channeledAd instanceof ChanneledAd ? $channeledAd->getId() : $channeledAd,
                'page' => $page instanceof Page ? $page->getId() : $page,
                'query' => $query instanceof Query ? $query->getId() : $query,
                'post' => $post instanceof Post ? $post->getId() : $post,
                'product' => $product instanceof Product ? $product->getId() : $product,
                'customer' => $customer instanceof Customer ? $customer->getId() : $customer,
                'order' => $order instanceof Order ? $order->getId() : $order,
                'country' => $country instanceof Country ? $country->getId() : $country,
                'device' => $device instanceof Device ? $device->getId() : $device,
            ], JSON_UNESCAPED_UNICODE)),
            /* Channel::google_analytics => md5(string: json_encode([
                'channel' => $channel instanceof Channel ? $channel->value : $channel,
                'name' => $name,
                'period' => $period instanceof Period ? $period->value : $period,
                'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
                'page' => $page instanceof Page ? $page->getId() : $page,
                'query' => $query instanceof Query ? $query->getId() : $query,
                'country' => $country instanceof Country ? $country->getId() : $country,
                'device' => $device instanceof Device ? $device->getId() : $device
            ], JSON_UNESCAPED_UNICODE)), */
            default => md5(string: json_encode([
                'channel' => $channel instanceof Channel ? $channel->getName() : $channel,
                'name' => $name,
                'period' => $period instanceof Period ? $period->value : $period,
                'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
                'account' => $account instanceof Account ? $account->getId() : $account,
                'channeledAccount' => $channeledAccount instanceof ChanneledAccount ? $channeledAccount->getId() : $channeledAccount,
                'campaign' => $campaign instanceof Campaign ? $campaign->getId() : $campaign,
                'channeledCampaign' => $channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getId() : $channeledCampaign,
                'channeledAdGroup' => $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getId() : $channeledAdGroup,
                'channeledAd' => $channeledAd instanceof ChanneledAd ? $channeledAd->getId() : $channeledAd,
                'page' => $page instanceof Page ? $page->getUrl() : $page,
                'query' => $query instanceof Query ? $query->getQuery() : $query,
                'post' => $post instanceof Post ? $post->getId() : $post,
                'product' => $product instanceof Product ? $product->getId() : $product,
                'customer' => $customer instanceof Customer ? $customer->getId() : $customer,
                'order' => $order instanceof Order ? $order->getId() : $order,
                'country' => $country instanceof Country ? $country->getCode() : ($country instanceof CountryEnum ? $country->value : $country),
                'device' => $device instanceof Device ? $device->getType() : ($device instanceof DeviceEnum ? $device->value : $device)
            ], JSON_UNESCAPED_UNICODE))
        };
    }

    public static function generateChanneledMetricKey(
        Channel|int|string $channel,
        string $platformId,
        Metric|int $metric,
        DateTime|string $platformCreatedAt
    ): string {
        return md5(json_encode([
            'channel' => $channel instanceof Channel ? $channel->getName() : (is_numeric($channel) ? Channel::from($channel)->getName() : $channel),
            'platformId' => $platformId,
            'metric_id' => $metric instanceof Metric ? $metric->getId() : $metric,
            'platformCreatedAt' => $platformCreatedAt instanceof DateTime ? $platformCreatedAt->format('Y-m-d') : $platformCreatedAt
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function generateChanneledMetricDimensionKey(
        ChanneledMetric|int $channeledMetric,
        string $dimensionKey,
        ?string $dimensionValue
    ): string {
        return md5(($channeledMetric instanceof ChanneledMetric ? $channeledMetric->getId() : $channeledMetric) . $dimensionKey . $dimensionValue);
    }
}