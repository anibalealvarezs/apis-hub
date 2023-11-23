<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use Classes\Conversions\ShopifyConvert;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Analytics\Customer;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws GuzzleException
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $manager = Helpers::getManager();
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $sourceCustomers = $shopifyClient->getAllCustomers(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            fields: $fields,
            ids: $filters->ids ?? null,
            sinceId: $filters->sinceId ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
        $channeledCustomersCollection = ShopifyConvert::customers($sourceCustomers['customers']);
        $customerRepository = $manager->getRepository(Customer::class);
        $channeledCustomerRepository = $manager->getRepository(ChanneledCustomer::class);
        foreach ($channeledCustomersCollection as $channeledCustomer) {
            if (!$channeledCustomer->email) {
                continue;
            }
            if (!$customerEntity = $customerRepository->getByEmail($channeledCustomer->email)) {
                $customerEntity = $customerRepository->create(
                    data: (object) ['email' => $channeledCustomer->email,],
                    returnEntity: true,
                );
            }
            if (!$channeledCustomerEntity = $channeledCustomerRepository->getByPlatformIdAndChannel($channeledCustomer->platformId, $channeledCustomer->channel)) {
                $channeledCustomerEntity = $channeledCustomerRepository->create(
                    data: $channeledCustomer,
                    returnEntity: true,
                );
            }
            if (empty($channeledCustomerEntity->getData())) {
                $channeledCustomerEntity
                    ->addPlatformId($channeledCustomer->platformId)
                    ->addData($channeledCustomer->data);
            }
            $customerEntity->addChanneledCustomer($channeledCustomerEntity);
            $manager->persist($customerEntity);
            $manager->persist($channeledCustomerEntity);
            $manager->flush();
        }
        return new Response(json_encode($sourceCustomers));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromKlaviyo(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromFacebook(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return Response
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null): Response
    {
        return new Response(json_encode([]));
    }
}