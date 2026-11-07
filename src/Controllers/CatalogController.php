<?php

declare(strict_types=1);

namespace Controllers;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Services\Aggregation\EntityAggregationEngine;
use Services\Aggregation\EntityMeasureCatalog;
use Services\Catalog\CatalogReadService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CatalogController extends BaseController
{
    private CatalogReadService $catalogService;
    private EntityAggregationEngine $aggregationEngine;
    private EntityMeasureCatalog $measureCatalog;

    public function __construct(
        ?EntityManagerInterface $em = null,
        ?CatalogReadService $catalogService = null,
        ?EntityAggregationEngine $aggregationEngine = null,
        ?EntityMeasureCatalog $measureCatalog = null
    ) {
        if ($em instanceof \Doctrine\ORM\EntityManager) {
            $this->em = $em;
        } elseif ($catalogService === null && $em === null) {
            parent::__construct();
        }

        $emInstance = $em ?? $this->em;
        $this->catalogService = $catalogService ?? new CatalogReadService($emInstance);
        $this->measureCatalog = $measureCatalog ?? new EntityMeasureCatalog();
        $this->aggregationEngine = $aggregationEngine ?? new EntityAggregationEngine($emInstance->getConnection(), $this->measureCatalog);
    }

    public function capabilities(Request $request): Response
    {
        try {
            $payload = $this->parsePayload($request);
            $data = $this->catalogService->capabilities($payload);

            return $this->createResponse(
                data: $data,
                status: 'success',
                httpStatus: Response::HTTP_OK
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function aggregateCapabilities(Request $request): Response
    {
        try {
            $data = $this->measureCatalog->getCapabilities();

            return $this->createResponse(
                data: $data,
                status: 'success',
                httpStatus: Response::HTTP_OK
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function aggregate(Request $request): Response
    {
        try {
            $payload = $this->parsePayload($request);
            $entityType = isset($payload['entity_type']) && is_string($payload['entity_type'])
                ? trim($payload['entity_type'])
                : '';

            if ($entityType === '') {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing required parameter: entity_type.',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            // Extract authorized accounts from authorized_scope or direct channel/account_ids shorthand
            $scope = is_array($payload['authorized_scope'] ?? null) ? $payload['authorized_scope'] : [];
            if (empty($scope['channels']) && !empty($payload['channel']) && (!empty($payload['account_ids']) || !empty($payload['platform_ids']))) {
                $ch = strtolower(trim((string)$payload['channel']));
                $scope['channels'] = [
                    $ch => [
                        'account_ids'  => (array)($payload['account_ids'] ?? []),
                        'platform_ids' => (array)($payload['platform_ids'] ?? []),
                    ],
                ];
            }

            $accounts = !empty($payload['authorized_accounts']) && is_array($payload['authorized_accounts'])
                ? $payload['authorized_accounts']
                : $this->catalogService->resolveAuthorizedAccounts($scope);

            $result = $this->aggregationEngine->aggregate($entityType, $payload, $accounts);

            return $this->createResponse(
                data: $result['data'],
                status: 'success',
                httpStatus: Response::HTTP_OK,
                meta: $result['meta'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function list(Request $request): Response
    {
        try {
            $payload = $this->parsePayload($request);
            $entityType = isset($payload['entity_type']) && is_string($payload['entity_type'])
                ? trim($payload['entity_type'])
                : '';

            if ($entityType === '') {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing required parameter: entity_type.',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->catalogService->list($entityType, $payload);

            return $this->createResponse(
                data: $result['data'],
                status: 'success',
                httpStatus: Response::HTTP_OK,
                meta: $result['meta'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function profile(Request $request): Response
    {
        try {
            $payload = $this->parsePayload($request);
            $entityType = isset($payload['entity_type']) && is_string($payload['entity_type'])
                ? trim($payload['entity_type'])
                : '';
            $reference = isset($payload['reference']) && is_string($payload['reference'])
                ? trim($payload['reference'])
                : '';

            if ($entityType === '' || $reference === '') {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing required parameters: entity_type and reference.',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->catalogService->profile($entityType, $reference, $payload);
            if ($result === null) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Catalog entity record not found in authorized scope.',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            return $this->createResponse(
                data: $result,
                status: 'success',
                httpStatus: Response::HTTP_OK
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function activity(Request $request): Response
    {
        try {
            $payload = $this->parsePayload($request);
            $entityType = isset($payload['entity_type']) && is_string($payload['entity_type'])
                ? trim($payload['entity_type'])
                : '';
            $reference = isset($payload['reference']) && is_string($payload['reference'])
                ? trim($payload['reference'])
                : '';

            if ($entityType === '' || $reference === '') {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Missing required parameters: entity_type and reference.',
                    httpStatus: Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->catalogService->activity($entityType, $reference, $payload);
            if ($result === null) {
                return $this->createResponse(
                    data: null,
                    status: 'error',
                    error: 'Catalog entity record not found in authorized scope.',
                    httpStatus: Response::HTTP_NOT_FOUND
                );
            }

            return $this->createResponse(
                data: $result,
                status: 'success',
                httpStatus: Response::HTTP_OK,
                meta: $result['meta'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return $this->createResponse(
                data: null,
                status: 'error',
                error: $e->getMessage(),
                httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        $content = $request->getContent();
        $decoded = [];
        if (is_string($content) && trim($content) !== '') {
            $json = json_decode($content, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        $query = $request->query->all();

        return array_replace($query, $decoded);
    }
}
