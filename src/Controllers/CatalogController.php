<?php

declare(strict_types=1);

namespace Controllers;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Services\Catalog\CatalogReadService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CatalogController extends BaseController
{
    private CatalogReadService $catalogService;

    public function __construct(?EntityManagerInterface $em = null, ?CatalogReadService $catalogService = null)
    {
        if ($em instanceof \Doctrine\ORM\EntityManager) {
            $this->em = $em;
        } elseif ($catalogService === null && $em === null) {
            parent::__construct();
        }

        $this->catalogService = $catalogService ?? new CatalogReadService($em ?? $this->em);
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
