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
            $qb->where('j.entity = :entity')
               ->andWhere('j.channel = :channel')
               ->andWhere('j.status IN (:statuses)')
               ->setParameter('entity', $entity)
               ->setParameter('channel', $channel->name)
               ->setParameter('statuses', [\Enums\JobStatus::scheduled->value, \Enums\JobStatus::processing->value]);
            
            $existingJobs = $qb->getQuery()->getResult();
            if (count($existingJobs) > 0) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'There is already an active caching process for this endpoint.',
                    httpStatus: Response::HTTP_CONFLICT
                );
            }

            // Create job
            $jobData = (object) [
                'entity' => $entity,
                'channel' => $channel->name,
                'status' => \Enums\JobStatus::scheduled->value,
                'payload' => [
                    'body' => $body,
                    'params' => $params
                ]
            ];
            $jobRepo->create($jobData);

            return $this->createResponse(
                data: ['message' => 'Caching job successfully scheduled in background.'],
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
        return in_array(needle: $entity, haystack: array_keys(array: $crudEntities));
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
        } catch (Exception) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: "Entity " . $entity . " not found in AnalyticsEntities",
                httpStatus: Response::HTTP_NOT_FOUND
            );
        }
    }
}