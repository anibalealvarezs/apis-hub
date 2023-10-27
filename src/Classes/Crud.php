<?php

declare(strict_types=1);

namespace Classes;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Entities\Job;
use Helpers\Helpers;

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
     * @return string
     * @throws NotSupported
     */
    public function __invoke(string $entity, string $method, int $id = null, string $data = null): string
    {
        $entity = match ($entity) {
            'job' => Job::class,
            default => null,
        };

        if (!$entity) {
            return 'Entity not found';
        }

        $repository = $this->em->getRepository($entity);
        switch ($method) {
            case 'read':
                if ($id) {
                    return json_encode($repository->read($id));
                }
                if ($collection = $repository->readMultiple(filters: Helpers::dataToObject($data))) {
                    $response = [];
                    foreach ($collection as $element) {
                        $response[] = $repository->read($element->getId());
                    }
                    return json_encode($response);
                }
                return 'No results';
            case 'create':
                return json_encode($repository->create(Helpers::dataToObject($data)));
            case 'update':
                return json_encode($repository->update($id, Helpers::dataToObject($data)));
            case 'delete':
                if ($repository->delete($id)) {
                    return 'Entity (' . $entity . ') with id (' . $id . ') deleted';
                }
                return 'Entity (' . $entity . ') with id (' . $id . ') could not be deleted';
            default:
                return 'Method not found';
        }
    }
}
