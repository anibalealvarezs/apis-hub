<?php

namespace Classes\Requests;

use Doctrine\Common\Collections\ArrayCollection;
use Interfaces\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class DiscountRequests implements RequestInterface
{
    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromShopify(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode(['Discounts are retrieved along with Price Rules.']));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    static function process(ArrayCollection $channeledCollection): Response
    {
        // TODO: Implement process() method.

        return new Response(json_encode(['Discounts processed']));
    }
}