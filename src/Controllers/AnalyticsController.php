<?php

namespace Controllers;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Enums\AnalyticsEntities;
use Enums\Channels;
use Helpers\Helpers;
use ReflectionEnum;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsController
{
    /**
     * @var EntityManager
     */
    protected EntityManager $em;

    /**
     * @throws Exception
     * @throws ORMException
     */
    public function __construct()
    {
        $this->em = Helpers::getManager();
    }

    /**
     * @param string $entity
     * @param string $channel
     * @param string|null $data
     * @return Response
     * @throws NotSupported
     */
    public function __invoke(string $entity, string $channel, string $data = null): Response
    {
        if (!$this->isValidEntity($entity)) {
            return new Response('Invalid analytics entity', Response::HTTP_NOT_FOUND);
        }

        return $this->list(
            entity: $entity,
            channel: (new ReflectionEnum(Channels::class))->getConstant($channel)
        );
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param string|null $data
     * @return Response
     * @throws NotSupported
     */
    protected function list(string $entity, Channels $channel, string $data = null): Response
    {
        $methodNotFound = new Response('Method not found', Response::HTTP_NOT_FOUND);
        $requestsClassName = $this->getEntityRequestsClassName($entity);
        return match($channel) {
            Channels::shopify => method_exists($requestsClassName, 'getListFromShopify') ? $requestsClassName::getListFromShopify() : $methodNotFound,
            Channels::klaviyo => method_exists($requestsClassName, 'getListFromKlaviyo') ? $requestsClassName::getListFromKlaviyo() : $methodNotFound,
            Channels::facebook => method_exists($requestsClassName, 'getListFromFacebook') ? $requestsClassName::getListFromFacebook() : $methodNotFound,
            Channels::bigcommerce => method_exists($requestsClassName, 'getListFromBigcommerce') ? $requestsClassName::getListFromBigcommerce() : $methodNotFound,
            Channels::netsuite => method_exists($requestsClassName, 'getListFromNetsuite') ? $requestsClassName::getListFromNetsuite() : $methodNotFound,
            Channels::amazon => method_exists($requestsClassName, 'getListFromAmazon') ? $requestsClassName::getListFromAmazon() : $methodNotFound,
            default => $methodNotFound,
        };
    }

    /**
     * @param string $entity
     * @return bool
     */
    protected function isValidEntity(string $entity): bool
    {
        $crudEntities = Helpers::getCrudEntities();

        return in_array($entity, array_keys($crudEntities));
    }

    /**
     * @param string $entity
     * @return string
     */
    protected function getEntityRequestsClassName(string $entity): string
    {
        return (new ReflectionEnum(AnalyticsEntities::class))->getConstant($entity)->getRequestsClassName();
    }
}