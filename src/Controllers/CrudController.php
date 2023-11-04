<?php

namespace Controllers;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Helpers\Helpers;
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
     * @param string|null $data
     * @param bool $cli
     * @return Response
     * @throws NotSupported
     */
    public function __invoke(string $entity, string $method, int $id = null, string $data = null, bool $cli = false): Response
    {
        if (!$this->isValidCrudableEntity($entity)) {
            return new Response('Invalid crudable entity', 404);
        }

        if (!$cli) {
            header('Content-Type: application/json');
        }

        return match ($method) {
            'read' => $this->read($entity, $id, $data),
            'create' => $this->create($entity, $data),
            'update' => $this->update($entity, $id, $data),
            'delete' => $this->delete($entity, $id),
            default => new Response('Method not found', 404),
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
        return new Response(json_encode($repository->read($id)));
    }

    /**
     * @param string $entity
     * @param string|null $data
     * @return Response
     * @throws NotSupported
     */
    protected function list(string $entity, string $data = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        if ($collection = $repository->readMultiple(filters: Helpers::dataToObject($data))) {
            return new Response(
                json_encode(array_map(
                    function ($element) use ($repository) {
                        return $repository->read($element->getId());
                    }, $collection
                ))
            );
        }

        return new Response(json_encode([]));
    }

    /**
     * @param string $entity
     * @param string|null $data
     * @return Response
     * @throws NotSupported
     */
    protected function create(string $entity, string $data = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        return new Response(json_encode($repository->create(Helpers::dataToObject($data))));
    }

    /**
     * @param string $entity
     * @param int|null $id
     * @param string|null $data
     * @return Response
     * @throws NotSupported
     */
    protected function update(string $entity, int $id = null, string $data = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getCrudEntities()[strtolower($entity)]['class']
        );

        return new Response(json_encode($repository->update($id, Helpers::dataToObject($data))));
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
        $crudEntities = Helpers::getCrudEntities();

        if (!isset($crudEntities[strtolower($entity)])) {
            return false;
        }

        return $crudEntities[strtolower($entity)]['enabled'];
    }
}