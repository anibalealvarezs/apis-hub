<?php

namespace Controllers;

use Enums\AnalyticsEntity;
use Enums\Channel;
use Exception;
use Helpers\Helpers;
use InvalidArgumentException;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response;

class CacheController extends BaseController
{
    /**
     * @param string $channel
     * @param string $entity
     * @param string|null $body
     * @param array|null $params
     * @return Response
     * @throws ReflectionException
     */
    public function __invoke(
        string $channel,
        string $entity,
        ?string $body = null,
        ?array $params = null
    ): Response {
        $channelEnum = Channel::tryFromName($channel);
        if (!$channelEnum) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "Invalid channel: " . $channel,
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        if (!$this->isValidEntity(entity: $entity)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: 'Invalid analytics entity',
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        $requestsClassName = $this->getEntityRequestsClassName($entity);
        if (method_exists($requestsClassName, 'supportedChannels') && !in_array($channelEnum, $requestsClassName::supportedChannels(), true)) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "Channel ". $channelEnum->getCommonName() ." not supported for entity " . $entity,
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }

        return $this->list(
            entity: $entity,
            channel: $channelEnum,
            body: $body,
            params: $params
        );
    }

    /**
     * @param array|null $params
     * @param string|null $body
     * @param string $className
     * @param string $methodName
     * @return array
     * @throws ReflectionException
     */
    protected function prepareAnalyticsParams(?array $params, ?string $body, string $className, string $methodName): array
    {
        $bodyData = (array) Helpers::bodyToObject(data: $body);
        $queryParams = $params ?? [];

        // Correctly handle 'filters' if it's explicitly provided in the body
        if (isset($bodyData['filters']) && is_array($bodyData['filters'])) {
            $bodyData['filters'] = (object) $bodyData['filters'];
        }

        // Combine body data and query params
        $allInputs = array_merge($bodyData, $queryParams);

        // Use Reflection to only pass parameters that the method expects
        $reflection = new \ReflectionMethod($className, $methodName);
        $methodParams = $reflection->getParameters();

        $finalParams = [];
        $extraParams = [];

        foreach ($allInputs as $key => $value) {
            $isKnownParam = false;
            foreach ($methodParams as $methodParam) {
                if ($methodParam->getName() === $key) {
                    $finalParams[$key] = $value;
                    $isKnownParam = true;
                    break;
                }
            }
            if (!$isKnownParam) {
                $extraParams[$key] = $value;
            }
        }

        // If the method expects 'filters', put all unknown inputs there
        foreach ($methodParams as $methodParam) {
            if ($methodParam->getName() === 'filters') {
                $currentFilters = (array) ($finalParams['filters'] ?? []);
                $finalParams['filters'] = (object) array_merge($currentFilters, $extraParams);
                break;
            }
        }

        return $finalParams;
    }

    /**
     * @param string $entity
     * @param Channel $channel
     * @param string|null $body
     * @param array|null $params
     * @return Response
     */
    protected function list(string $entity, Channel $channel, ?string $body = null, ?array $params = null): Response
    {
        try {
            /** @var \Repositories\JobRepository $jobRepo */
            $jobRepo = $this->em->getRepository(\Entities\Job::class);
            $qb = $jobRepo->createQueryBuilder('j');
            
            $equivalents = [$entity];
            if (str_contains($entity, 'channeled_')) {
                $equivalents[] = str_replace('channeled_', '', $entity);
            } else {
                $equivalents[] = 'channeled_' . $entity;
            }

            $qb->where('j.entity IN (:entities)')
               ->andWhere('j.channel = :channel')
               ->andWhere('j.status IN (:statuses)')
               ->setParameter('entities', array_unique($equivalents))
               ->setParameter('channel', $channel->name)
               ->setParameter('statuses', [\Enums\JobStatus::scheduled->value, \Enums\JobStatus::processing->value]);

            if ($this->isPostgreSQL()) {
                // PostgreSQL needs explicit casting from JSONB to TEXT for LIKE operator
                $payloadField = 'CAST(j.payload AS TEXT)';
            } else {
                $payloadField = 'j.payload';
            }

            // Be timeframe-specific to allow parallel jobs for different periods (e.g. gsc-jan vs gsc-feb)
            if ($params && isset($params['startDate'])) {
                $qb->andWhere("({$payloadField} LIKE :start_pattern1 OR {$payloadField} LIKE :start_pattern2)")
                    ->setParameter('start_pattern1', '%startDate%' . $params['startDate'] . '%')
                    ->setParameter('start_pattern2', '%start_date%' . $params['startDate'] . '%');
            }
            if ($params && isset($params['endDate'])) {
                $qb->andWhere("({$payloadField} LIKE :end_pattern1 OR {$payloadField} LIKE :end_pattern2)")
                    ->setParameter('end_pattern1', '%endDate%' . $params['endDate'] . '%')
                    ->setParameter('end_pattern2', '%end_date%' . $params['endDate'] . '%');
            }
            
            // be instance-specific if name is provided
            if ($params && isset($params['instance_name'])) {
                $qb->andWhere("{$payloadField} LIKE :instance_pattern")
                   ->setParameter('instance_pattern', '%instance_name%' . $params['instance_name'] . '%');
            }

            $existingJobs = $qb->getQuery()->getResult();
            if (count($existingJobs) > 0) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'There is already an active caching process for this endpoint and timeframe.',
                    httpStatus: Response::HTTP_CONFLICT
                );
            }

            $payload = [
                'body' => $body,
                'params' => $params
            ];
            
            if (isset($params['instance_name'])) {
                $payload['instance_name'] = $params['instance_name'];
                unset($params['instance_name']);
                $payload['params'] = $params;
            }

            // --- ATOMIC LOCK START ---
            $redis = Helpers::getRedisClient();
            $lockKey = 'lock:schedule:' . sha1($channel->name . $entity . json_encode($payload));
            
            // Try to acquire lock for 30 seconds
            if (!$redis->set($lockKey, 'locked', 'EX', 30, 'NX')) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Another process is currently scheduling this job. Please try again in a moment.',
                    httpStatus: Response::HTTP_CONFLICT
                );
            }

            try {
                // Secondary check inside lock to be 100% sure
                $existingJobsInner = $qb->getQuery()->getResult();
                if (count($existingJobsInner) > 0) {
                    $redis->del($lockKey);
                    return $this->createResponse(
                        data: null,
                        status: 'error',
                        error: 'There is already an active caching process for this endpoint.',
                        httpStatus: Response::HTTP_CONFLICT
                    );
                }

                $jobData = (object) [
                    'entity' => $entity,
                    'channel' => $channel->name,
                    'status' => \Enums\JobStatus::scheduled->value,
                    'payload' => $payload
                ];
                $jobRepo->create($jobData);
            } finally {
                // We keep the lock for a few seconds to avoid immediate retries from other containers
                // but we could delete it if we are sure of the uniqueness
            }
            // --- ATOMIC LOCK END ---

            return $this->createResponse(
                data: ['message' => 'Caching job successfully scheduled in background.'],
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
     * @param string|null $channel
     * @param string|null $entity
     * @return Response
     */
    public function interruptJobs(?string $channel = null, ?string $entity = null): Response
    {
        try {
            /** @var \Repositories\JobRepository $jobRepo */
            $jobRepo = $this->em->getRepository(\Entities\Job::class);
            $qb = $jobRepo->createQueryBuilder('j')
                ->update()
                ->set('j.status', \Enums\JobStatus::failed->value)
                ->where('j.status IN (:statuses)')
                ->setParameter('statuses', [\Enums\JobStatus::scheduled->value, \Enums\JobStatus::processing->value]);

            if ($channel) {
                $channelEnum = Channel::tryFromName($channel);
                if ($channelEnum) {
                    $qb->andWhere('j.channel = :channel')->setParameter('channel', $channelEnum->name);
                } else {
                    $qb->andWhere('j.channel = :channel')->setParameter('channel', $channel);
                }
            }
            if ($entity) {
                $qb->andWhere('j.entity = :entity')->setParameter('entity', $entity);
            }

            $count = $qb->getQuery()->execute();

            return $this->createResponse(
                data: ['message' => "$count caching jobs interrupted."],
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
     * @return bool
     */
    protected function isValidEntity(string $entity): bool
    {
        $crudEntities = Helpers::getEntitiesConfig();
        if (in_array(needle: $entity, haystack: array_keys(array: $crudEntities))) {
            return true;
        }

        return AnalyticsEntity::tryFrom($entity) !== null;
    }

    /**
     * @param string $entity
     * @return string
     */
    protected function getEntityRequestsClassName(string $entity): string
    {
        $enum = AnalyticsEntity::tryFrom($entity);
        if (!$enum) {
            throw new InvalidArgumentException("Invalid entity: " . $entity);
        }
        return $enum->getRequestsClassName();
    }

    /**
     * Fetch data and cache it.
     *
     * @param string $entity
     * @param Channel $channel
     * @param array|null $params
     * @param string|null $body
     * @return mixed
     * @throws ReflectionException
     */
    public function fetchData(string $entity, Channel $channel, ?array $params = null, ?string $body = null): mixed
    {
        try {
            $requestsClassName = $this->getEntityRequestsClassName($entity);
            $methodName = 'getListFrom' . $channel->getCommonName();

            if (!method_exists($requestsClassName, $methodName)) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: "Channel " . $channel->getCommonName() . " not supported for entity " . $entity,
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            $parameters = $this->prepareAnalyticsParams($params, $body, $requestsClassName, $methodName);

            /* if (!$this->validateParams(array_keys($parameters), $requestsClassName, $methodName)) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Invalid parameters',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            } */

            return $requestsClassName::$methodName(...$parameters) ?: [];
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: "error",
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        } catch (Exception $e) {
            throw $e;
        }
    }
}
