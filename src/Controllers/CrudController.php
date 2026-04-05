<?php

namespace Controllers;

use Doctrine\ORM\Exception\NotSupported;
use Exception;
use Helpers\Helpers;
use InvalidArgumentException;
use ReflectionException;
use Services\CacheKeyGenerator;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class CrudController extends BaseController
{
    protected CacheService $cacheService;
    protected CacheKeyGenerator $cacheKeyGenerator;

    public function __construct()
    {
        $this->cacheService = CacheService::getInstance(redisClient: Helpers::getRedisClient());
        $this->cacheKeyGenerator = new CacheKeyGenerator();
        parent::__construct();
    }

    /**
     * @throws ReflectionException
     * @throws NotSupported
     */
    public function __invoke(
        string $entity,
        string $method,
        int|string|null $id = null,
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

        $hideFields = array_filter(array_map('trim', explode(',', (is_array($params) && isset($params['hideFields'])) ? $params['hideFields'] : '')));

        return match ($method) {
            'read'   => $this->read(entity: $entity, id: $id, hideFields: $hideFields),
            'count'  => $this->count(entity: $entity, body: $body, params: $params),
            'list'   => $this->list(entity: $entity, body: $body, params: $params, hideFields: $hideFields),
            'create' => $this->create(entity: $entity, body: $body),
            'update' => $this->update(entity: $entity, id: $id, body: $body),
            'delete'    => $this->delete(entity: $entity, id: $id),
            'aggregate' => $this->aggregate(entity: $entity, body: $body, params: $params),
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
     * @param string $entityName
     * @param string|null $body
     * @return array
     */
    protected function prepareReadMultipleParams(
        ?array $params,
        string $entityName,
        ?string $body
    ): array {
        return $this->prepareCrudParams(params: $params, body: $body);
    }

    /**
     * @param string $entity
     * @param int|null $id
     * @return Response
     * @throws NotSupported
     */
    protected function read(string $entity, int|string|null $id = null, array $hideFields = []): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity);
            if ($hideFields) {
                $repository->setHideFields($hideFields);
            }
            if ($entity === 'job') {
                $data = $repository->read(id: $id);
            } else {
                $cacheKey = $this->cacheKeyGenerator->forEntity($entity, $id) . ($hideFields ? '_' . implode('_', $hideFields) : '');
                $data = $this->cacheService->get(
                    key: $cacheKey,
                    callback: function () use ($repository, $id) {
                        return $repository->read(id: $id);
                    }
                );
            }

            if (!$data) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: "Record with ID " . ($id ?? 'unknown') . " not found",
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
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported|ReflectionException
     */
    protected function count(string $entity, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity);
            $params = $this->prepareReadMultipleParams(
                params: $params,
                entityName: $entity,
                body: $body
            );

            $hasBodyFilters = !empty($body) && trim($body) !== '{}' && trim($body) !== '[]';

            if ($hasBodyFilters || $entity === 'job') {
                $count = $repository->countElements(filters: $params['filters']);
            } else {
                $cacheKey = 'count_' . $entity . '_' . md5(json_encode($params));
                $count = $this->cacheService->get(
                    key: $cacheKey,
                    callback: function () use ($repository, $params) {
                        return $repository->countElements(filters: $params['filters']);
                    }
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
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported|ReflectionException
     */
    protected function list(string $entity, ?string $body = null, ?array $params = null, array $hideFields = []): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity);
            if ($hideFields) {
                $repository->setHideFields($hideFields);
            }
            $params = $this->prepareReadMultipleParams(
                params: $params,
                entityName: $entity,
                body: $body
            );

            $hasBodyFilters = !empty($body) && trim($body) !== '{}' && trim($body) !== '[]';

            if ($hasBodyFilters || $entity === 'job') {
                $data = $repository->readMultiple(...$params)->toArray();
            } else {
                $cacheKey = 'list_' . $entity . '_' . md5(json_encode($params)) . ($hideFields ? '_' . implode('_', $hideFields) : '');
                $data = $this->cacheService->get(
                    key: $cacheKey,
                    callback: function () use ($repository, $params) {
                        return $repository->readMultiple(...$params)->toArray();
                    }
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
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws NotSupported
     */
    protected function aggregate(string $entity, ?string $body = null, ?array $params = null): Response
    {
        try {
            $repository = $this->getRepository(entity: $entity);
            $params = $this->prepareCrudParams(params: $params, body: $body);

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
     * @param string|null $body
     * @return Response
     * @throws NotSupported
     */
    protected function create(string $entity, ?string $body = null): Response
    {
        try {
            $data = Helpers::bodyToObject(data: $body);
            $repository = $this->getRepository(entity: $entity);
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
                    channel: $this->extractChannel($result)
                );
            }

            return $this->createResponse(
                data: (is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
                status: 'success',
                httpStatus: Response::HTTP_CREATED
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
     * @param int|null $id
     * @param string|null $body
     * @return Response
     * @throws NotSupported
     */
    protected function update(string $entity, int|string|null $id = null, ?string $body = null): Response
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

            $repository = $this->getRepository(entity: $entity);
            $result = $repository->update(
                id: $id,
                data: Helpers::bodyToObject(data: $body)
            );

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
                channel: $this->extractChannel($result)
            );

            return $this->createResponse(
                data: (is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : (array)$result),
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
     * @param int|null $id
     * @return Response
     * @throws NotSupported
     */
    protected function delete(string $entity, int|string|null $id = null): Response
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

            $repository = $this->getRepository(entity: $entity);
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
                entities: [$entity => $id]
            );

            return $this->createResponse(
                data: null,
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
            if (method_exists($result, 'getOrderId')) {
                return $result->getOrderId();
            }
            if (method_exists($result, 'getPlatformId')) {
                return $result->getPlatformId();
            }
        }
        if (is_array($result) && isset($result['id'])) {
            return $result['id'];
        }
        return null;
    }

    /**
     * Extract channel from result (entity or array)
     * @param mixed $result
     * @return string|null
     */
    protected function extractChannel(mixed $result): ?string
    {
        if (is_object($result) && method_exists($result, 'getChannel')) {
            return $result->getChannel();
        }
        if (is_array($result) && isset($result['channel'])) {
            return $result['channel'];
        }
        return null;
    }
}
