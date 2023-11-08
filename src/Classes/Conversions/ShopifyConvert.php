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
}