<?php

namespace Controllers;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Enums\Channels;
use Helpers\Helpers;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

class ChanneledCrudController
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
     * @param string $method
     * @param int|null $id
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported
     * @throws ReflectionException
     */
    public function __invoke(string $entity, string $channel, string $method, int $id = null, string $body = null, ?array $params = null): Response
    {
        if (!$this->isValidCrudableEntity($entity)) {
            return new Response('Invalid crudable entity', Response::HTTP_NOT_FOUND);
        }

        $channelsConfig = Helpers::getChannelsConfig();

        if ((defined(Channels::class . '::' . $channel) === false) || !in_array($channel, array_keys($channelsConfig))) {
            return new Response('Invalid channel', Response::HTTP_NOT_FOUND);
        }

        if ($channelsConfig[$channel]['enabled'] === false) {
            return new Response('Channel disabled', Response::HTTP_FORBIDDEN);
        }

        $channelConstant = (new ReflectionEnum(objectOrClass: Channels::class))->getConstant($channel);

        return match ($method) {
            'read' => $this->read($entity, $channelConstant, $id),
            'list' => $this->list($entity, $channelConstant, $body, $params),
            default => new Response('Method not found', Response::HTTP_NOT_FOUND),
        };
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param int|null $id
     * @return Response
     * @throws NotSupported
     */
    protected function read(string $entity, Channels $channel, int $id = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getEntitiesConfig()[strtolower($entity)]['channeled_class']
        );

        $params = [
            'id' => $id,
            'filters' => (object) [
                'channel' => $channel->value
            ],
        ];

        return new Response(json_encode($repository->read(...$params) ?: []));
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported
     * @throws ReflectionException
     */
    protected function list(string $entity, Channels $channel, string $body = null, ?array $params = null): Response
    {
        $repository = $this->em->getRepository(
            Helpers::getEntitiesConfig()[strtolower($entity)]['channeled_class']
        );

        if (!empty($params) && !$this->validateParams(array_keys($params), $repository::class, 'readMultiple')) {
            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        $params['filters'] = Helpers::bodyToObject($body);
        if (!isset($params['filters']->channel)) {
            $params['filters']->channel = $channel->value;
        }

        return new Response(json_encode($repository->readMultiple(...$params)->toArray()));
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