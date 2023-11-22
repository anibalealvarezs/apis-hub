<?php

namespace Classes\Conversions;

use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channels;

class ShopifyConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        $convertedCustomers = new ArrayCollection();

        foreach ($customers as $customer) {
            $customerObject = (object) [
                'platformId' => $customer['id'],
                'channel' => Channels::shopify->value,
                'email' => $customer['email'],
                'data' => $customer,
            ];
            $convertedCustomers->add($customerObject);
        }

        return $convertedCustomers;
    }

    /**
     * @param array $discounts
     * @return ArrayCollection
     */
    public static function discounts(array $discounts): ArrayCollection
    {
        $convertedDiscounts = new ArrayCollection();

        foreach ($discounts as $discount) {
            $discountObject = (object) [
                'platformId' => $discount['id'],
                'channel' => Channels::shopify->value,
                'code' => $discount['code'],
                'data' => $discount,
            ];
            $convertedDiscounts->add($discountObject);
        }

        return $convertedDiscounts;
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        $convertedPriceRules = new ArrayCollection();

        foreach ($priceRules as $priceRule) {
            $priceRuleObject = (object) [
                'platformId' => $priceRule['id'],
                'channel' => Channels::shopify->value,
                'data' => $priceRule,
            ];
            $convertedPriceRules->add($priceRuleObject);
        }

        return $convertedPriceRules;
    }

    public static function orders(array $orders): ArrayCollection
    {
        $convertedOrders = new ArrayCollection();

        foreach ($orders as $order) {
            $orderObject = (object) [
                'platformId' => $order['id'],
                'channel' => Channels::shopify->value,
                'email' => $order['email'],
                'data' => $order,
                'discountCodes' => $order['discount_codes'] ?? '',
                'lineItems' => $order['line_items'] ?? '',
            ];
            $convertedOrders->add($orderObject);
        }

        return $convertedOrders;
    }
}