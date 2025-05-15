<?php

namespace Controllers;

use Enums\AnalyticsEntities;
use Enums\Channels;
use Exception;
use Helpers\Helpers;
use ReflectionEnum;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response;

class CacheController extends BaseController
{
    /**
     * @param string $channel
     * @param string $entity
     * @param string|null $body
     * @param array|null $params
     * @return Response
     */
    public function __invoke(
        string $channel,
        string $entity,
        ?string $body = null,
        ?array $params = null
    ): Response {
        if (!$this->isValidEntity(entity: $entity)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid analytics entity',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        return $this->list(
            entity: $entity,
            channel: (new ReflectionEnum(objectOrClass: Channels::class))->getConstant(name: $channel),
            body: $body,
            params: $params
        );
    }

    /**
     * @param array|null $params
     * @param string|null $body
     * @return array
     */
    protected function prepareAnalyticsParams(?array $params, ?string $body): array
    {
        $parameters = $body ? json_decode($body, true) : null;
        if (isset($parameters['filters'])) {
            $parameters['filters'] = (object) $parameters['filters'];
        }

        if (!$params) {
            $params = [];
        }
        foreach ($params as $key => $value) {
            $parameters[$key] = $value;
        }

        return $parameters ?: [];
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     */
    protected function list(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $data = $this->fetchData($entity, $channel, $params, $body);

            return $this->createResponse(
                data: $data ?: [],
                status: 'success'
            );
        } catch (Exception $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @param string $entity
     * @return bool
     */
    protected function isValidEntity(string $entity): bool
    {
        $crudEntities = Helpers::getEntitiesConfig();
        return in_array(needle: $entity, haystack: array_keys(array: $crudEntities));
    }

    /**
     * @param string $entity
     * @return string
     * @throws ReflectionException
     */
    protected function getEntityRequestsClassName(string $entity): string
    {
        return (new ReflectionEnum(objectOrClass: AnalyticsEntities::class))->getConstant(name: $entity)->getRequestsClassName();
    }

    /**
     * Fetch data and cache it.
     *
     * @param string $entity
     * @param Channels $channel
     * @param array|null $params
     * @param string|null $body
     * @return mixed
     * @throws ReflectionException
     */
    protected function fetchData(string $entity, Channels $channel, ?array $params, ?string $body): mixed
    {
        $requestsClassName = '\Classes\Requests\\' . $this->getEntityRequestsClassName($entity);
        $methodName = 'getListFrom' . $channel->getCommonName();

        if (!method_exists($requestsClassName, $methodName)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Method not found',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $parameters = $this->prepareAnalyticsParams($params, $body);

        if (!$this->validateParams(array_keys($parameters), $requestsClassName, $methodName)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid parameters',
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        }

        return $requestsClassName::$methodName(...$parameters) ?: [];
    }
}