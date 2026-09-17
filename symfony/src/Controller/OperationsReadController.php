<?php

declare(strict_types=1);

namespace App\Controller;

use Kernel\Operations\Service\OperationsSectionReader;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Migration-only read API for proving semantic parity with the legacy
 * CosRuntimeController before public routing moves to Symfony.
 *
 * Tenant identity is resolved from the authenticated request context and is
 * never accepted from request parameters.
 */
final class OperationsReadController
{
    public function __construct(
        private readonly OperationsSectionReader $operations,
        private readonly TenantContextProviderInterface $tenantContext,
    ) {
    }

    public function actions(): JsonResponse
    {
        return $this->section('actions');
    }

    public function action(string $id): JsonResponse
    {
        return $this->section('actions', $id);
    }

    public function approvals(): JsonResponse
    {
        return $this->section('approvals');
    }

    public function agents(): JsonResponse
    {
        return $this->section('agent_runs');
    }

    public function rules(): JsonResponse
    {
        return $this->section('rules');
    }

    public function events(): JsonResponse
    {
        return $this->section('events');
    }

    public function audit(): JsonResponse
    {
        return $this->section('audit');
    }

    private function section(string $section, ?string $id = null): JsonResponse
    {
        $context = $this->tenantContext->current();
        if ($context === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Authenticated tenant context required.',
            ], Response::HTTP_FORBIDDEN);
        }

        $organizationId = $context->organizationId()->value();

        try {
            if ($id !== null) {
                $item = $this->operations->item($organizationId, $section, $id, 100);

                return $item !== null
                    ? new JsonResponse(['ok' => true, 'data' => $item])
                    : new JsonResponse(['ok' => false, 'error' => 'Resource not found.'], 404);
            }

            return new JsonResponse([
                'ok' => true,
                'data' => $this->operations->section($organizationId, $section, 100),
            ]);
        } catch (Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'COS runtime query failed.',
            ], 500);
        }
    }
}
