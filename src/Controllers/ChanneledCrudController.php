<?php

namespace Controllers;

use Enums\Channels;
use Exception;
use Helpers\Helpers;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
use Services\CacheKeyGenerator;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class ChanneledCrudController extends BaseController
{
    private CacheService $cacheService;
    private CacheKeyGenerator $cacheKeyGenerator;

    public function __construct()
    {
        $this->cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
        $this->cacheKeyGenerator = new CacheKeyGenerator();
        parent::__construct();
    }

    /**
     * @param string $entity
     * @param string $channel
     * @param string $method
     * @param int|null $id
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    public function __invoke(
        string $entity,
        string $channel,
        string $method,
        ?int $id = null,
        ?string $body = null,
        ?array $params = null
    ): Response {
        if (!$this->isValidCrudableEntity(entity: $entity)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid crudable entity',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $channelsConfig = Helpers::getChannelsConfig();

        if (!defined(constant_name: Channels::class . '::' . $channel) || !in_array(needle: $channel, haystack: array_keys(array: $channelsConfig))) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid channel',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if ($channelsConfig[$channel]['enabled'] === false) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Channel disabled',
                httpStatus: Response::HTTP_FORBIDDEN
            );
        }

        $channelConstant = (new ReflectionEnum(objectOrClass: Channels::class))->getConstant(name: $channel);

        return match ($method) {
            'read' => $this->read(entity: $entity, channel: $channelConstant, id: $id),
            'count' => $this->count(entity: $entity, channel: $channelConstant, body: $body, params: $params),
            'list' => $this->list(entity: $entity, channel: $channelConstant, body: $body, params: $params),
            default => $this->createResponse(
                data: null,
                status: 'error',
                error: 'Method not found',
                httpStatus: Response::HTTP_NOT_FOUND
            ),
        };
    }

    /**
     * @param array|null $params
     * @param string $repositoryClass
     * @param string|null $body
     * @param Channels $channel
     * @return array
     */
    protected function prepareChanneledReadMultipleParams(
        ?array $params,
        string $repositoryClass,
        ?string $body,
        Channels $channel
    ): array {
        if (!empty($params) && !$this->validateParams(params: array_keys(array: $params), entity: $repositoryClass, method: 'readMultiple')) {
            throw new InvalidArgumentException(message: 'Invalid parameters');
        }

        $params = $params ?? [];
        $params['filters'] = Helpers::bodyToObject(data: $body) ?? new \stdClass();
        if (!isset($params['filters']->channel)) {
            $params['filters']->channel = $channel->value;
        }

        return $params;
    }

    /**
     * @param string $entity
     * @param Channels $channel
     * @param int|null $id
     * @return Response
     */
    protected function read(string $entity, Channels $channel, ?int $id = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $params = [
                'id' => $id,
                'filters' => (object) [
                    'channel' => $channel->value
                ],
            ];

            $cacheKey = $this->cacheKeyGenerator->forChanneledEntity($channel->value, $entity, $id);

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->read(...$params)
            );

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
     * @param Channels $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function count(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $repository::class,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_count_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']));

            $count = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->countElements(filters: $params['filters']),
                ttl: 300
            );

            return $this->createResponse(
                data: ['count' => $count],
                status: 'success'
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
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
     * @param Channels $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function list(string $entity, Channels $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $repository::class,
                body: $body,
                channel: $channel
            );

            $cacheKey = 'channeled_list_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']));

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: fn() => $repository->readMultiple(...$params)->toArray(),
                ttl: 600
            );

            return $this->createResponse(
                data: $data ?: [],
                status: 'success'
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
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
     * @param Channels $channel
     * @param string|null $body
     * @return Response
     */
    protected function create(string $entity, Channels $channel, ?string $body = null): Response
    {
        try {
            $data = Helpers::bodyToObject(data: $body);
            if (!isset($data->channel)) {
                $data->channel = $channel->value;
            }
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $result = $repository->create(data: $data);

            if (!$result) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Invalid or missing data',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            // Invalidate caches
            $id = $this->extractId($result);
            if ($id) {
                $this->cacheService->invalidateMultipleEntities(
                    entities: [$entity => $id],
                    channel: $channel->value
                );
            }

            return $this->createResponse(
                data: (method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
                status: 'success',
                httpStatus: Response::HTTP_CREATED
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
     * @param Channels $channel
     * @param int|null $id
     * @param string|null $body
     * @return Response
     */
    protected function update(string $entity, Channels $channel, ?int $id = null, ?string $body = null): Response
    {
        try {
            if (!$id) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Invalid or missing ID',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $data = Helpers::bodyToObject(data: $body);
            if (!isset($data->channel)) {
                $data->channel = $channel->value;
            }
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $result = $repository->update(id: $id, data: $data);

            if (!$result) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Record not found or could not be updated',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            // Invalidate caches
            $this->cacheService->invalidateMultipleEntities(
                entities: [$entity => $id],
                channel: $channel->value
            );

            return $this->createResponse(
                data: (method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
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
     * @param Channels $channel
     * @param int|null $id
     * @return Response
     */
    protected function delete(string $entity, Channels $channel, ?int $id = null): Response
    {
        try {
            if (!$id) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing ID',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $success = $repository->delete(id: $id);

            if (!$success) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Record not found or could not be deleted',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            // Invalidate caches
            $this->cacheService->invalidateMultipleEntities(
                entities: [$entity => $id],
                channel: $channel->value
            );

            return $this->createResponse(
                data: null,
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
     * Extract ID from result (entity or array)
     * @param mixed $result
     * @return int|string|null
     */
    protected function extractId(mixed $result): int|string|null
    {
        if (is_object($result)) {
            if (method_exists($result, 'getId')) {
                return $result->getId();
            }
            if (method_exists($result, 'getPlatformId')) {
                return $result->getPlatformId();
            }
            if (method_exists($result, 'getCode') && $result instanceof \Entities\Analytics\Channeled\ChanneledDiscount) {
                return $result->getCode();
            }
        }
        if (is_array($result) && isset($result['id'])) {
            return $result['id'];
        }
        if (is_array($result) && isset($result['code'])) {
            return $result['code'];
        }
        return null;
    }
}
