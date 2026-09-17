<?php

declare(strict_types=1);

namespace App\Controller;

use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class SecurityContextController
{
    public function __construct(private readonly TenantContextProviderInterface $tenantContext)
    {
    }

    public function __invoke(): JsonResponse
    {
        $context = $this->tenantContext->current();
        if ($context === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Manager authorization required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => [
                'user_id' => (int) $context->userId()->value(),
                'organization_id' => $context->organizationId()->value(),
                'organization_role' => $context->role()->value(),
                'permissions' => array_map(
                    static fn ($permission): string => $permission->value(),
                    $context->permissions(),
                ),
            ],
        ]);
    }
}
