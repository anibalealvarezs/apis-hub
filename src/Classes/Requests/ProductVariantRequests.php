<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\KlaviyoApi\Conversions\KlaviyoConvert;
use Anibalealvarezs\KlaviyoApi\KlaviyoApi;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductVariantRequests implements RequestInterface
{
    

    /**
     * @param \Enums\Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public static function getList(
        Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): Response {
        $chanEnum = ($channel instanceof Channel) ? $channel : Channel::tryFromName((string)$channel);
        $method = 'getListFrom' . $chanEnum->getCommonName();
        return self::$method(
                filters: $filters,
                resume: $filters->resume ?? true,
                jobId: $jobId
            );
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromShopify(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode(['Product variants are retrieved along with Products.']));
    }

    /**
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     */
    public static function getListFromKlaviyo(array $fields = null, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );
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
        $sourceVariants = $klaviyoClient->getAllCatalogVariants(
            catalogVariantsFields: $fields,
            filter: $formattedFilters,
        );

        return self::process(KlaviyoConvert::productVariants($sourceVariants['data']));
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
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
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(
        ArrayCollection $channeledCollection,
    ): Response {
        // Pending
        return new Response(json_encode(['Variants processed']));
    }
}
