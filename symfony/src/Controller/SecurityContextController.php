<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\LegacySecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class SecurityContextController
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof LegacySecurityUser) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Manager authorization required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => [
                'user_id' => $user->id(),
                'organization_id' => $user->organizationId(),
                'organization_role' => $user->organizationRole(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }
}
