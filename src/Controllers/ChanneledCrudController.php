<?php

namespace Controllers;

use Enums\Channel;
use Exception;
use Helpers\Helpers;
use InvalidArgumentException;
use ReflectionEnum;
use ReflectionException;
use Services\CacheKeyGenerator;
use Services\CacheService;
use stdClass;
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
        $isRegisteredInEnum = defined(constant_name: Channel::class . '::' . $channel);
        $isConfigured = in_array(needle: $channel, haystack: array_keys(array: $channelsConfig));

        if (!$isRegisteredInEnum) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "The channel '$channel' is not a valid channel in the system. Please check the Channel enum.",
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if (!$isConfigured) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "The channel '$channel' is not configured in your project. Please add it to the 'channels' section in your deploy/project.yaml.",
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if (($channelsConfig[$channel]['enabled'] ?? false) === false) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "The channel '$channel' is currently disabled in your project configuration.",
                httpStatus: Response::HTTP_FORBIDDEN
            );
        }

        $channelConstant = (new ReflectionEnum(objectOrClass: Channel::class))->getConstant(name: $channel);
        $rawData    = filter_var($params['rawData'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hideFields = array_filter(array_map('trim', explode(',', $params['hideFields'] ?? '')));

        return match ($method) {
            'read'  => $this->read(entity: $entity, channel: $channelConstant, id: $id, rawData: $rawData, hideFields: $hideFields),
            'count' => $this->count(entity: $entity, channel: $channelConstant, body: $body, params: $params),
            'list'      => $this->list(entity: $entity, channel: $channelConstant, body: $body, params: $params, rawData: $rawData, hideFields: $hideFields),
            'aggregate' => $this->aggregate(entity: $entity, channel: $channelConstant, body: $body, params: $params),
            default     => $this->createResponse(
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
     * @param Channel $channel
     * @return array
     */
    protected function prepareChanneledReadMultipleParams(
        ?array $params,
        string $repositoryClass,
        ?string $body,
        Channel $channel
    ): array {
        $params = $this->prepareCrudParams(params: $params, body: $body);

        if (!isset($params['filters']->channel)) {
            $params['filters']->channel = $channel->value;
        }

        return $params;
    }

    /**
     * @param string $entity
     * @param Channel $channel
     * @param int|null $id
     * @return Response
     */
    protected function read(string $entity, Channel $channel, ?int $id = null, bool $rawData = false, array $hideFields = []): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');

            if ($rawData && method_exists($repository, 'setIncludeRawData')) {
                $repository->setIncludeRawData(true);
            }
            if ($hideFields) {
                $repository->setHideFields($hideFields);
            }

            $params = [
                'id' => $id,
                'filters' => (object) [
                    'channel' => $channel->value
                ],
            ];

            $cacheKey = $this->cacheKeyGenerator->forChanneledEntity($channel->value, $entity, $id)
                . ($rawData ? '_raw' : '')
                . ($hideFields ? '_' . implode('_', $hideFields) : '');

            $data = $this->cacheService->get(
                key: $cacheKey,
                callback: function () use ($repository, $params) {
                    return $repository->read(...$params);
                }
            );

            if (!$data) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: "Record with ID " . ($id ?? 'unknown') . " not found for for channel " . $channel->name,
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            return $this->createResponse(
                data: $data,
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
     * @param Channel $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function count(string $entity, Channel $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $repository::class,
                body: $body,
                channel: $channel
            );

            $hasBodyFilters = !empty($body) && trim($body) !== '{}' && trim($body) !== '[]';

            if ($hasBodyFilters) {
                $count = $repository->countElements(filters: $params['filters']);
            } else {
                $cacheKey = 'channeled_count_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']));

                $count = $this->cacheService->get(
                    key: $cacheKey,
                    callback: function () use ($repository, $params) {
                        return $repository->countElements(filters: $params['filters']);
                    },
                    ttl: 300
                );
            }

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
     * @param Channel $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function list(string $entity, Channel $channel, ?string $body = null, ?array $params = null, bool $rawData = false, array $hideFields = []): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');

            if ($rawData && method_exists($repository, 'setIncludeRawData')) {
                $repository->setIncludeRawData(true);
            }
            if ($hideFields) {
                $repository->setHideFields($hideFields);
            }

            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $repository::class,
                body: $body,
                channel: $channel
            );

            $hasBodyFilters = !empty($body) && trim($body) !== '{}' && trim($body) !== '[]';

            if ($hasBodyFilters) {
                $data = $repository->readMultiple(...$params)->toArray();
            } else {
                $cacheKey = 'channeled_list_' . $entity . '_' . $channel->value . '_' . md5(serialize($params['filters']))
                    . ($rawData ? '_raw' : '')
                    . ($hideFields ? '_' . implode('_', $hideFields) : '');

                $data = $this->cacheService->get(
                    key: $cacheKey,
                    callback: function () use ($repository, $params) {
                        return $repository->readMultiple(...$params)->toArray();
                    },
                    ttl: 600
                );
            }

            $meta = array_filter(
                $params,
                fn ($k) => !in_array($k, ['filters', 'extra']),
                ARRAY_FILTER_USE_KEY
            );

            return $this->createResponse(
                data: $data ?: [],
                status: 'success',
                meta: $meta ?: null
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
     * @param Channel $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    protected function aggregate(string $entity, Channel $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity, configKey: 'channeled_class');
            $params = $this->prepareChanneledReadMultipleParams(
                params: $params,
                repositoryClass: $repository::class,
                body: $body,
                channel: $channel
            );

            $aggregations = (array) ($params['aggregations'] ?? []);
            $groupBy = (array) ($params['groupBy'] ?? []);

            if (empty($aggregations)) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing aggregations parameter',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $data = $repository->aggregate(
                aggregations: $aggregations,
                groupBy: $groupBy,
                filters: $params['filters'] ?? null,
                startDate: $params['startDate'] ?? null,
                endDate: $params['endDate'] ?? null
            );

            return $this->createResponse(
                data: $data,
                status: 'success'
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: "error",
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
     * @param Channel $channel
     * @param string|null $body
     * @return Response
     */
    protected function create(string $entity, Channel $channel, ?string $body = null): Response
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
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: "error",
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
     * @param Channel $channel
     * @param int|null $id
     * @param string|null $body
     * @return Response
     */
    protected function update(string $entity, Channel $channel, ?int $id = null, ?string $body = null): Response
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
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: "error",
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
     * @param Channel $channel
     * @param int|null $id
     * @return Response
     */
    protected function delete(string $entity, Channel $channel, ?int $id = null): Response
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
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: "error",
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
