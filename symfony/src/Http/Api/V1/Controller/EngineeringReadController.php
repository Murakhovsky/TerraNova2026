<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Engineering\Application\Service\EngineeringStatusService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

final readonly class EngineeringReadController
{
    public function __construct(
        private EngineeringStatusService $engineering,
        private TenantContextProviderInterface $tenants,
    ) {}

    public function status(string $id): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager() || !$tenant->allows(TenantPermissions::MANAGE)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'manager_required',
                'message' => 'Manager authorization required.',
            ], 403);
        }

        try {
            return new JsonResponse(['ok' => true, 'data' => $this->engineering->status($id)]);
        } catch (Throwable $error) {
            $code = str_contains(strtolower($error->getMessage()), 'not found') ? 404 : 500;
            return new JsonResponse([
                'ok' => false,
                'error' => 'engineering_status_failed',
                'message' => $error->getMessage(),
            ], $code);
        }
    }
}
