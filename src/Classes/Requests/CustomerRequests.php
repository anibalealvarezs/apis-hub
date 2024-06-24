<?php

namespace Classes\Requests;

use Carbon\Carbon;
use Chmw\KlaviyoApi\KlaviyoApi;
use Classes\Conversions\KlaviyoConvert;
use Classes\Conversions\ShopifyConvert;
use Classes\Overrides\ShopifyApi\ShopifyApi;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Analytics\Customer;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class CustomerRequests implements RequestInterface
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws GuzzleException
     */
    public static function getListFromShopify(string $createdAtMin = null, string $createdAtMax = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );

        $shopifyClient->getAllCustomers(
            createdAtMin: $createdAtMin,
            createdAtMax: $createdAtMax,
            fields: $fields,
            ids: $filters->ids ?? null,
            sinceId: $filters->sinceId ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
            callback: function($customers) {
                self::process(ShopifyConvert::customers($customers));
            }
        );
        return new Response(json_encode(['Customers retrieved']));
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @return Response
     * @throws Exception
     * @throws GuzzleException
     * @throws NonUniqueResultException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function getListFromKlaviyo(string $createdAtMin = null, string $createdAtMax = null, array $fields = null, object $filters = null): Response
    {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );
        $origin = Carbon::parse(time: "2000-01-01");
        $min = $createdAtMin ? Carbon::parse($createdAtMin) : null;
        $max = $createdAtMax ? Carbon::parse($createdAtMax) : null;
        $now = Carbon::now();
        $from = $min && $min->lt($now) && $min->lt($max) && $origin->lte($min) ?
            $min->format(format: 'Y-m-d H:i:s') :
            $origin->format(format: "Y-m-d H:i:s");
        $to = $max && $max->lte($now) ?
            $max->format(format: 'Y-m-d H:i:s') :
            $now->format(format: 'Y-m-d H:i:s');
        $formattedFilters = [];
        if ($filters) {
            foreach ($filters as $key => $value) {
                $formattedFilters[] = [
                    "operator" => 'equals',
                    "field" => $key,
                    "value" => $value,
                ];
            }
        }
        $sourceCustomers = $klaviyoClient->getAllProfiles(
            profileFields: $fields,
            additionalFields: ['predictive_analytics','subscriptions'],
            filter: [
                ...$formattedFilters,
                ...[
                    ["operator" => ["greater-than"], "field" => "created", "value" => $from],
                    ["operator" => ["less-than"], "field" => "created", "value" => $to],
                ]
            ],
        );
        return self::process(KlaviyoConvert::customers($sourceCustomers['data']));
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

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     * @throws Exception
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public static function process(
        ArrayCollection $channeledCollection
    ): Response {
        $manager = Helpers::getManager();
        $customerRepository = $manager->getRepository(entityName: Customer::class);
        $channeledCustomerRepository = $manager->getRepository(entityName: ChanneledCustomer::class);
        foreach ($channeledCollection as $channeledCustomer) {
            if (!$channeledCustomer->email) {
                continue;
            }
            if (!$customerRepository->existsByEmail($channeledCustomer->email)) {
                $customerEntity = $customerRepository->create(
                    data: (object)['email' => $channeledCustomer->email,],
                    returnEntity: true,
                );
            } else {
                $customerEntity = $customerRepository->getByEmail($channeledCustomer->email);
            }
            if (!$channeledCustomerRepository->existsByPlatformIdAndChannel(
                platformId: $channeledCustomer->platformId,
                channel: $channeledCustomer->channel)
            ) {
                $channeledCustomerEntity = $channeledCustomerRepository->create(
                    data: $channeledCustomer,
                    returnEntity: true,
                );
            } else {
                $channeledCustomerEntity = $channeledCustomerRepository->getByPlatformIdAndChannel(
                    platformId: $channeledCustomer->platformId,
                    channel: $channeledCustomer->channel,
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
        return new Response(json_encode(['Customers processed']));
    }
}