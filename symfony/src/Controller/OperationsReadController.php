<?php

declare(strict_types=1);

namespace App\Controller;

use Kernel\Operations\Service\OperationsSectionReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * Migration-only read API for proving semantic parity with the legacy
 * CosRuntimeController before authentication and public routing move.
 *
 * The organization is fixed by runtime configuration. It is deliberately
 * not accepted from the request so this temporary surface cannot bypass
 * tenant isolation.
 */
final class OperationsReadController
{
    private readonly string $organizationId;

    public function __construct(
        private readonly OperationsSectionReader $operations,
        string $organizationId,
    ) {
        $organizationId = trim($organizationId);
        $this->organizationId = $organizationId !== '' ? $organizationId : 'default';
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
        try {
            if ($id !== null) {
                $item = $this->operations->item($this->organizationId, $section, $id, 100);

                return $item !== null
                    ? new JsonResponse(['ok' => true, 'data' => $item])
                    : new JsonResponse(['ok' => false, 'error' => 'Resource not found.'], 404);
            }

            return new JsonResponse([
                'ok' => true,
                'data' => $this->operations->section($this->organizationId, $section, 100),
            ]);
        } catch (Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'COS runtime query failed.',
            ], 500);
        }
    }
}
