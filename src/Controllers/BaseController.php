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
        $config = Helpers::getEntitiesConfig()[strtolower($entity)] ?? null;
        if (!$config || empty($config['repository_methods'][$method]['parameters'])) {
            return false;
        }

        $validParams = $config['repository_methods'][$method]['parameters'];
        return empty(array_diff($params, $validParams));
    }

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
}