<?php

namespace Enums;

enum AnalyticsEntities
{
    /**
     * @var string
     */
    case customer;

    /**
     * @var string
     */
    case discount;

    /**
     * @var string
     */
    case order;

    /**
     * @var string
     */
    case product;

    /**
     * @var string
     */
    case productCategory;

    /**
     * @var string
     */
    case vendor;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getRequestsClassName(): string
    {
        return match($this->name) {
            'customer' => 'CustomerRequests',
            'discount' => 'DiscountRequests',
            'order' => 'OrderRequests',
            'product' => 'ProductRequests',
            'productCategory' => 'ProductCategoryRequests',
            'vendor' => 'VendorRequests',
        };
    }
}
