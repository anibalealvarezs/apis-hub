<?php

namespace Controllers;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Helpers\Helpers;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

class CrudController
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
     * @param string $method
     * @param int|null $id
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported|ReflectionException
     */
    public function __invoke(string $entity, string $method, int $id = null, string $body = null, ?array $params = null): Response
    {
        if (!$this->isValidCrudableEntity($entity)) {
            return new Response('Invalid crudable entity', Response::HTTP_NOT_FOUND);
        }

        return match ($method) {
            'read' => $this->read($entity, $id),
            'list' => $this->list($entity, $body, $params),
            'create' => $this->create($entity, $body),
            'update' => $this->update($entity, $id, $body),
            'delete' => $this->delete($entity, $id),
            default => new Response('Method not found', Response::HTTP_NOT_FOUND),
        };
    }

    /**
     * @param string $entity
     * @param int|null $id
     * @return Response
     * @throws NotSupported
     */
    protected function read(string $entity, int $id = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        return new Response(json_encode($repository->read($id) ?: []));
    }

    /**
     * @param string $entity
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported|ReflectionException
     */
    protected function list(string $entity, string $body = null, ?array $params = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        if (!empty($params) && !$this->validateParams(array_keys($params), $repository::class, 'readMultiple')) {
            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        $params['filters'] = Helpers::bodyToObject($body);

        return new Response(json_encode($repository->readMultiple(...$params)));
    }

    /**
     * @param string $entity
     * @param string|null $body
     * @return Response
     * @throws NotSupported
     */
    protected function create(string $entity, string $body = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        return new Response(json_encode($repository->create(Helpers::bodyToObject($body))));
    }

    /**
     * @param string $entity
     * @param int|null $id
     * @param string|null $body
     * @return Response
     * @throws NotSupported
     */
    protected function update(string $entity, int $id = null, string $body = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        return new Response(json_encode($repository->update($id, Helpers::bodyToObject($body))));
    }

    /**
     * @param string $entity
     * @param int|null $id
     * @return Response
     * @throws NotSupported
     */
    protected function delete(string $entity, int $id = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        if ($repository->delete($id)) {
            return new Response('Record successfully deleted');
        }

        return new Response('Record could not be deleted');
    }

    /**
     * @param string $entity
     * @return bool
     */
    protected function isValidCrudableEntity(string $entity): bool
    {
        $crudEntities = Helpers::getEnabledCrudEntities();

        return in_array(strtolower($entity), array_keys($crudEntities));
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