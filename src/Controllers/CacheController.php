<?php

namespace Controllers;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Enums\AnalyticsEntities;
use Enums\Channels;
use Helpers\Helpers;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

class CacheController
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
     * @param string $channel
     * @param string $entity
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    public function __invoke(string $channel, string $entity, ?string $body = null, ?array $params = null): Response
    {
        if (!$this->isValidEntity($entity)) {
            return new Response('Invalid analytics entity', Response::HTTP_NOT_FOUND);
        }

        return $this->list(
            entity: $entity,
            channel: (new ReflectionEnum(Channels::class))->getConstant($channel),
            body: $body,
            params: $params,
        );
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function list(string $entity, Channels $channel, string $body = null, ?array $params = null): Response
    {
        $requestsClassName = '\Classes\Requests\\'.$this->getEntityRequestsClassName($entity);

        if (!method_exists($requestsClassName, 'getListFrom'.$channel->getCommonName())) {
            return new Response('Method not found', Response::HTTP_NOT_FOUND);
        }

        $parameters = $body ? json_decode($body, true) : null;
        if (isset($parameters['filters'])) {
            $parameters['filters'] = (object) $parameters['filters'];
        }

        foreach($params as $key => $value) {
            $parameters[$key] = $value;
        }

        if (!$this->validateParams(array_keys($parameters), $requestsClassName, 'getListFrom'.$channel->getCommonName())) {
            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        return $requestsClassName::{'getListFrom'.Channels::shopify->getCommonName()}(...$parameters);
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

    /**
     * @param array $params
     * @param string $class
     * @param string $method
     * @return bool
     * @throws ReflectionException
     */
    protected function validateParams(array $params, string $class, string $method): bool
    {
        $r = new ReflectionMethod($class, $method);
        $methodParams = $r->getParameters();
        return empty(array_diff($params, array_map(function($param) {
            return $param->getName();
        }, $methodParams)));
    }
}