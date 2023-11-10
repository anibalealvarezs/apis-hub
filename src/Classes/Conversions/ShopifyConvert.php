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
            $customerEntity = (object) [
                'platformId' => $customer['id'],
                'channel' => Channels::shopify->value,
                'data' => json_encode($customer),
            ];
            $convertedCustomers->add($customerEntity);
        }

        return $convertedCustomers;
    }

    public static function discounts(array $discounts): ArrayCollection
    {
        $convertedDiscounts = new ArrayCollection();

        foreach ($discounts as $discount) {
            $discountEntity = (object) [
                'platformId' => $discount['id'],
                'channel' => Channels::shopify->value,
                'data' => json_encode($discount),
            ];
            $convertedDiscounts->add($discountEntity);
        }

        return $convertedDiscounts;
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        $convertedPriceRules = new ArrayCollection();

        foreach ($priceRules as $priceRule) {
            $priceRuleEntity = (object) [
                'platformId' => $priceRule['id'],
                'channel' => Channels::shopify->value,
                'data' => json_encode($priceRule),
            ];
            $convertedPriceRules->add($priceRuleEntity);
        }

        return $convertedPriceRules;
    }
}