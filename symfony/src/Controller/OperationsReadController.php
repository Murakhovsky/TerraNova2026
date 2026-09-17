<?php

declare(strict_types=1);

namespace App\Controller;

use Kernel\Operations\Contract\OperationsReadModelInterface;
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
        private readonly OperationsReadModelInterface $operations,
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
            $overview = $this->operations->overview($this->organizationId, 100);
            $items = $overview[$section] ?? [];

            if ($id !== null) {
                foreach ($items as $item) {
                    if (($item['id'] ?? null) === $id) {
                        return new JsonResponse(['ok' => true, 'data' => $item]);
                    }
                }

                return new JsonResponse([
                    'ok' => false,
                    'error' => 'Resource not found.',
                ], 404);
            }

            return new JsonResponse(['ok' => true, 'data' => $items]);
        } catch (Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'COS runtime query failed.',
            ], 500);
        }
    }
}
