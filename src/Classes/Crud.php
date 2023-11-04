<?php

declare(strict_types=1);

namespace Classes;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Response;

require_once  __DIR__ . '/../../vendor/autoload.php';

class Crud
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
        $crudEntities = Helpers::getCrudEntities();

        if (!isset($crudEntities[strtolower($entity)])) {
            return new Response('Invalid crudable entity', 404);
        }

        $entityEnabled = $crudEntities[strtolower($entity)]['enabled'];
        if (!$entityEnabled) {
            return new Response('CRUD disabled for this entity', 403);
        }

        $entityClass = $crudEntities[strtolower($entity)]['class'];

        if (!$cli) {
            header('Content-Type: application/json');
        }

        $repository = $this->em->getRepository($entityClass);
        switch ($method) {
            case 'read':
                if ($id) {
                    return new Response(json_encode($repository->read($id)));
                }
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
            case 'create':
                return new Response(json_encode($repository->create(Helpers::dataToObject($data))));
            case 'update':
                return new Response(json_encode($repository->update($id, Helpers::dataToObject($data))));
            case 'delete':
                if ($repository->delete($id)) {
                    return new Response('Entity (' . $entityClass . ') with id (' . $id . ') deleted');
                }
                return new Response('Entity (' . $entityClass . ') with id (' . $id . ') could not be deleted');
            default:
                return new Response('Method not found', 404);
        }
    }
}
