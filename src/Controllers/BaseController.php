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
        int $httpStatus = Response::HTTP_OK
    ): Response {
        return new Response(
            content: json_encode(value: [
                'data' => $data,
                'status' => $status,
                'error' => $error
            ]),
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
        'limit', 'pagination', 'ids', 'filters', 'orderBy', 'orderDir'
    ];

    /**
     * @throws NotSupported
     * @throws Exception
     */
    protected function getRepository(string $entity, string $configKey = 'class'): object
    {
        $config = Helpers::getEntitiesConfig();
        $entityKey = strtolower($entity);
        if (!isset($config[$entityKey][$configKey])) {
            throw new Exception("Entity configuration for '$entity' with key '$configKey' not found");
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
    protected function prepareCrudParams(?array $params, ?string $body): array
    {
        $params = $params ?? [];
        $bodyData = (array) Helpers::bodyToObject(data: $body);

        $finalParams = [];
        $filters = (array) ($bodyData['filters'] ?? $bodyData);

        foreach ($params as $key => $value) {
            if (in_array($key, self::CRUD_TOP_LEVEL_PARAMS)) {
                $finalParams[$key] = $value;
            } else {
                $filters[$key] = $value;
            }
        }

        $finalParams['filters'] = (object) $filters;

        return $finalParams;
    }
}