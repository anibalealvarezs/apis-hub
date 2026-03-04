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
use Helpers\Helpers;
use InvalidArgumentException;

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
     * @param Account|string|null $account
     * @param ChanneledAccount|string|null $channeledAccount
     * @param Campaign|string|null $campaign
     * @param ChanneledCampaign|string|null $channeledCampaign
     * @param ChanneledAdGroup|string|null $channeledAdGroup
     * @param ChanneledAd|string|null $channeledAd
     * @param Page|string|null $page
     * @param Query|string|null $query
     * @param Post|string|null $post
     * @param Product|string|null $product
     * @param Customer|string|null $customer
     * @param Order|string|null $order
     * @param Country|CountryEnum|string|null $country
     * @param Device|DeviceEnum|string|null $device
     * @return string
     */
    public static function generateMetricConfigKey(
        Channel|int|string $channel,
        string $name,
        Period|string $period,
        DateTime|string $metricDate,
        Account|string|null $account = null,
        ChanneledAccount|string|null $channeledAccount = null,
        Campaign|string|null $campaign = null,
        ChanneledCampaign|string|null $channeledCampaign = null,
        ChanneledAdGroup|string|null $channeledAdGroup = null,
        ChanneledAd|string|null $channeledAd = null,
        Page|string|null $page = null,
        Query|string|null $query = null,
        Post|string|null $post = null,
        Product|string|null $product = null,
        Customer|string|null $customer = null,
        Order|string|null $order = null,
        Country|CountryEnum|string|null $country = null,
        Device|DeviceEnum|string|null $device = null
    ): string
    {
        /* Helpers::dumpDebugJson([
            'channel' => $channel instanceof Channel ? $channel->getName() : $channel,
            'name' => $name,
            'period' => $period instanceof Period ? $period->value : $period,
            'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
            'account' => $account instanceof Account ? $account->getName() : $account,
            'channeledAccount' => $channeledAccount instanceof ChanneledAccount ? $channeledAccount->getPlatformId() : $channeledAccount,
            'campaign' => $campaign instanceof Campaign ? $campaign->getCampaignId() : $campaign,
            'channeledCampaign' => $channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getPlatformId() : $channeledCampaign,
            'channeledAdGroup' => $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getId() : $channeledAdGroup,
            'channeledAd' => $channeledAd instanceof ChanneledAd ? $channeledAd->getId() : $channeledAd,
            'page' => $page instanceof Page ? $page->getUrl() : $page,
            'query' => $query instanceof Query ? $query->getQuery() : $query,
            'post' => $post instanceof Post ? $post->getId() : $post,
            'product' => $product instanceof Product ? $product->getId() : $product,
            'customer' => $customer instanceof Customer ? $customer->getEmail() : $customer,
            'order' => $order instanceof Order ? $order->getId() : $order,
            'country' => $country instanceof Country ? $country->getCode() : ($country instanceof CountryEnum ? $country->value : $country),
            'device' => $device instanceof Device ? $device->getType() : ($device instanceof DeviceEnum ? $device->value : $device),
            'value' => md5(string: json_encode([
                'channel' => $channel instanceof Channel ? $channel->getName() : $channel,
                'name' => $name,
                'period' => $period instanceof Period ? $period->value : $period,
                'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
                'account' => $account instanceof Account ? $account->getName() : $account,
                'channeledAccount' => $channeledAccount instanceof ChanneledAccount ? $channeledAccount->getPlatformId() : $channeledAccount,
                'campaign' => $campaign instanceof Campaign ? $campaign->getCampaignId() : $campaign,
                'channeledCampaign' => $channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getPlatformId() : $channeledCampaign,
                'channeledAdGroup' => $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getId() : $channeledAdGroup,
                'channeledAd' => $channeledAd instanceof ChanneledAd ? $channeledAd->getId() : $channeledAd,
                'page' => $page instanceof Page ? $page->getUrl() : $page,
                'query' => $query instanceof Query ? $query->getQuery() : $query,
                'post' => $post instanceof Post ? $post->getId() : $post,
                'product' => $product instanceof Product ? $product->getId() : $product,
                'customer' => $customer instanceof Customer ? $customer->getEmail() : $customer,
                'order' => $order instanceof Order ? $order->getId() : $order,
                'country' => $country instanceof Country ? $country->getCode() : ($country instanceof CountryEnum ? $country->value : $country),
                'device' => $device instanceof Device ? $device->getType() : ($device instanceof DeviceEnum ? $device->value : $device),
            ], JSON_UNESCAPED_UNICODE)),
        ]); */

        return match($channel) {
            Channel::google_search_console => md5(string: json_encode([
                'channel' => $channel->getName(),
                'name' => $name,
                'period' => $period instanceof Period ? $period->value : $period,
                'metricDate' => $metricDate instanceof DateTime ? $metricDate->format('Y-m-d') : $metricDate,
                'account' => $account instanceof Account ? $account->getName() : $account,
                'channeledAccount' => (string) ($channeledAccount instanceof ChanneledAccount ? $channeledAccount->getPlatformId() : $channeledAccount),
                'campaign' => (string) ($campaign instanceof Campaign ? $campaign->getCampaignId() : $campaign),
                'channeledCampaign' => (string) ($channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getPlatformId() : $channeledCampaign),
                'channeledAdGroup' => (string) $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getPlatformId() : $channeledAdGroup,
                'channeledAd' => (string) $channeledAd instanceof ChanneledAd ? $channeledAd->getPlatformId() : $channeledAd,
                'page' => $page instanceof Page ? $page->getUrl() : $page,
                'query' => $query instanceof Query ? $query->getQuery() : $query,
                'post' => (string) $post instanceof Post ? $post->getPostId() : $post,
                'product' => (string) $product instanceof Product ? $product->getProductId() : $product,
                'customer' => $customer instanceof Customer ? $customer->getEmail() : $customer,
                'order' => (string) $order instanceof Order ? $order->getOrderId() : $order,
                'country' => $country instanceof Country ? $country->getCode() : $country,
                'device' => $device instanceof Device ? $device->getType() : $device,
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
                'account' => $account instanceof Account ? $account->getName() : $account,
                'channeledAccount' => (string) ($channeledAccount instanceof ChanneledAccount ? $channeledAccount->getPlatformId() : $channeledAccount),
                'campaign' => (string) ($campaign instanceof Campaign ? $campaign->getCampaignId() : $campaign),
                'channeledCampaign' => (string) ($channeledCampaign instanceof ChanneledCampaign ? $channeledCampaign->getPlatformId() : $channeledCampaign),
                'channeledAdGroup' => (string) $channeledAdGroup instanceof ChanneledAdGroup ? $channeledAdGroup->getPlatformId() : $channeledAdGroup,
                'channeledAd' => (string) $channeledAd instanceof ChanneledAd ? $channeledAd->getPlatformId() : $channeledAd,
                'page' => $page instanceof Page ? $page->getUrl() : $page,
                'query' => $query instanceof Query ? $query->getQuery() : $query,
                'post' => (string) $post instanceof Post ? $post->getPostId() : $post,
                'product' => (string) $product instanceof Product ? $product->getProductId() : $product,
                'customer' => $customer instanceof Customer ? $customer->getEmail() : $customer,
                'order' => (string) $order instanceof Order ? $order->getOrderId() : $order,
                'country' => $country instanceof Country ? $country->getCode() : ($country instanceof CountryEnum ? $country->value : $country),
                'device' => $device instanceof Device ? $device->getType() : ($device instanceof DeviceEnum ? $device->value : $device)
            ], JSON_UNESCAPED_UNICODE))
        };
    }

    /**
     * @param Channel|int|string|null $channel
     * @param string|null $name
     * @param Period|string|null $period
     * @param DateTime|string|null $metricDate
     * @param Account|int|null $account
     * @param ChanneledAccount|int|null $channeledAccount
     * @param Campaign|int|null $campaign
     * @param ChanneledCampaign|int|null $channeledCampaign
     * @param ChanneledAdGroup|int|null $channeledAdGroup
     * @param ChanneledAd|int|null $channeledAd
     * @param Page|string|null $page
     * @param Query|string|null $query
     * @param Post|string|null $post
     * @param Product|int|null $product
     * @param Customer|int|null $customer
     * @param Order|int|null $order
     * @param Country|CountryEnum|string|null $country
     * @param Device|DeviceEnum|string|null $device
     * @param array $dimensions
     * @param string|null $dimensionsHash
     * @param string|null $metricConfigKey
     * @return string
     */
    public static function generateMetricKey(
        Channel|int|string|null $channel = null,
        ?string $name = null,
        Period|string|null $period = null,
        DateTime|string|null $metricDate = null,
        Account|int|null $account = null,
        ChanneledAccount|int|null $channeledAccount = null,
        Campaign|int|null $campaign = null,
        ChanneledCampaign|int|null $channeledCampaign = null,
        ChanneledAdGroup|int|null $channeledAdGroup = null,
        ChanneledAd|int|null $channeledAd = null,
        Page|string|null $page = null,
        Query|string|null $query = null,
        Post|string|null $post = null,
        Product|int|null $product = null,
        Customer|int|null $customer = null,
        Order|int|null $order = null,
        Country|CountryEnum|string|null $country = null,
        Device|DeviceEnum|string|null $device = null,
        array $dimensions = [],
        ?string $dimensionsHash = null,
        ?string $metricConfigKey = null,
    ): string
    {
        if (is_null($metricConfigKey)) {
            if (is_null($channel) || is_null($name) || is_null($period) || is_null($metricDate)) {
                throw new InvalidArgumentException('Channel, name, period and metricDate are required to generate a metric key.');
            }
            $metricConfigKey = self::generateMetricConfigKey(
                channel: $channel,
                name: $name,
                period: $period,
                metricDate: $metricDate,
                account: $account,
                channeledAccount: $channeledAccount,
                campaign: $campaign,
                channeledCampaign: $channeledCampaign,
                channeledAdGroup: $channeledAdGroup,
                channeledAd: $channeledAd,
                page: $page,
                query: $query,
                post: $post,
                product: $product,
                customer: $customer,
                order: $order,
                country: $country,
                device: $device
            );
        }
        if (is_null($dimensionsHash)) {
            self::sortDimensions($dimensions);
            $dimensionsHash = self::generateDimensionsHash($dimensions);
        }
        return md5(string: json_encode([
            'metricConfig' => $metricConfigKey,
            'dimensionsHash' => $dimensionsHash,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function sortDimensions(array &$dimensions): void
    {
        usort($dimensions, function ($a, $b) {
            return strcmp($a['dimensionKey'], $b['dimensionKey']);
        });
    }

    public static function generateDimensionsHash(array $dimensions): string {
        return md5(string: json_encode($dimensions, JSON_UNESCAPED_UNICODE));
    }

    // ORDENAR DIMENSIONS ANTES DE HASHEAR

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

    public static function generateCustomerKey(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    public static function generateChanneledCustomerKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }

    public static function generateProductKey(string $productId): string
    {
        return md5((string)$productId);
    }

    public static function generateChanneledProductKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }

    public static function generateVendorKey(string $name): string
    {
        return md5(strtolower(trim($name)));
    }

    public static function generateChanneledVendorKey(string $channel, string $name): string
    {
        return md5($channel . '_' . strtolower(trim($name)));
    }

    public static function generateProductVariantKey(string $productVariantId): string
    {
        return md5((string)$productVariantId);
    }

    public static function generateChanneledProductVariantKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }

    public static function generateProductCategoryKey(string $productCategoryId): string
    {
        return md5((string)$productCategoryId);
    }

    public static function generateChanneledProductCategoryKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }

    public static function generateOrderKey(string $orderId): string
    {
        return md5((string)$orderId);
    }

    public static function generateChanneledOrderKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }

    public static function generateDiscountKey(string $code): string
    {
        return md5((string)$code);
    }

    public static function generateChanneledDiscountKey(string $channel, string $code): string
    {
        return md5($channel . '_' . $code);
    }

    public static function generatePriceRuleKey(string $priceRuleId): string
    {
        return md5((string)$priceRuleId);
    }

    public static function generateChanneledPriceRuleKey(string $channel, string $platformId): string
    {
        return md5($channel . '_' . $platformId);
    }
}