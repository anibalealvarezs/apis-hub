<?php

namespace Controllers;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Exception;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseController
{
    protected EntityManager $em;

    public function __construct()
    {
        $this->em = Helpers::getManager();
    }

    protected function createResponse(
        mixed $data,
        string $status,
        ?string $error = null,
        int $httpStatus = Response::HTTP_OK,
        ?array $meta = null
    ): Response {
        $responseArray = [
            'data' => $data,
            'status' => $status,
            'error' => $error
        ];

        if ($meta !== null) {
            $responseArray['meta'] = $meta;
        }

        return new Response(
            content: json_encode(value: $responseArray),
            status: $httpStatus,
            headers: ['Content-Type' => 'application/json']
        );
    }

    protected function isValidCrudableEntity(string $entity): bool
    {
        $crudEntities = Helpers::getEnabledCrudEntities();
        return in_array(needle: strtolower(string: $entity), haystack: array_keys(array: $crudEntities));
    }

    protected function validateParams(array $params, string $entity, string $method): bool
    {
        $config = Helpers::getEntitiesConfig()[Helpers::toSnakeCase($entity)] ?? null;
        if (!$config || empty($config['repository_methods'][$method]['parameters'])) {
            return false;
        }

        $validParams = $config['repository_methods'][$method]['parameters'];
        return empty(array_diff($params, $validParams)) || empty($params);
    }

    /**
     * @throws NotSupported
     * @throws Exception
     */
    /**
     * Top-level parameters for repository methods.
     * Anything not in this list will be treated as a filter.
     */
    protected const CRUD_TOP_LEVEL_PARAMS = [
        'limit', 'pagination', 'ids', 'filters', 'orderBy', 'orderDir',
        'startDate', 'endDate', 'rawData', 'hideFields', 'aggregations', 'groupBy', 'extra'
    ];

    /**
     * @throws NotSupported
     * @throws Exception
     */
    protected function getRepository(string $entity, string $configKey = 'class'): object
    {
        $config = Helpers::getEntitiesConfig();
        $entityKey = strtolower($entity);

        // Fallback for channeled_class if missing (useful for entities that are already channeled)
        if ($configKey === 'channeled_class' && !isset($config[$entityKey][$configKey])) {
            $configKey = 'class';
        }

        if (!isset($config[$entityKey][$configKey])) {
            throw new Exception("The entity '$entity' is not correctly configured in your config/yaml/entitiesconfig.yaml. Missing '$configKey' entry.");
        }
        return $this->em->getRepository(
            entityName: $config[$entityKey][$configKey]
        );
    }

    /**
     * @param array|null $params
     * @param string|null $body
     * @return array
     */
    /**
     * Params that are valid top-level control params but should NOT be forwarded to the repository.
     * They are consumed by the controller layer only.
     */
    protected const CONTROLLER_ONLY_PARAMS = ['rawData', 'hideFields'];

    protected function prepareCrudParams(?array $params, ?string $body): array
    {
        $params = $params ?? [];
        $bodyData = (array) Helpers::bodyToObject(data: $body);

        $finalParams = [];
        $queryFilters = [];

        // 1. Extract Control Parameters from the URL (query params)
        foreach ($params as $key => $value) {
            if (in_array($key, self::CRUD_TOP_LEVEL_PARAMS)) {
                if ($key === 'filters') {
                    $queryFilters = array_merge($queryFilters, (array) $value);
                } elseif (!in_array($key, self::CONTROLLER_ONLY_PARAMS)) {
                    $finalParams[$key] = $value;
                }
            } else {
                $queryFilters[$key] = $value;
            }
        }

        // 2. Extract Business Filters and Control Params from the Body
        $bodyFilters = (array) ($bodyData['filters'] ?? $bodyData);
        $bodyHasTopLevel = isset($bodyData['filters']);

        if ($bodyHasTopLevel) {
            // If body has 'filters' key, treat other top-level keys as control params if not already set by URL
            foreach ($bodyData as $key => $value) {
                if ($key !== 'filters' && in_array($key, self::CRUD_TOP_LEVEL_PARAMS) && !isset($finalParams[$key])) {
                    $finalParams[$key] = $value;
                }
            }
        } else {
            // Otherwise, check all keys in bodyData. If it's a control param, move it to finalParams.
            foreach ($bodyFilters as $key => $value) {
                if (in_array($key, self::CRUD_TOP_LEVEL_PARAMS)) {
                    if (!isset($finalParams[$key])) {
                        $finalParams[$key] = $value;
                    }
                    unset($bodyFilters[$key]);
                }
            }
        }

        // 3. Merge: URL filters override Body filters
        $finalParams['filters'] = (object) array_merge($bodyFilters, $queryFilters);

        return $finalParams;
    }
}
