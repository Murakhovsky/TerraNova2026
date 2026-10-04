<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class AccountAccessController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'account',
            activeSection: '',
            activeItem: 'access',
        );
        $shell = $this->shells->create($tenant, $context, 'Доступ і дозволи', [
            new ShellBreadcrumb('Cabinet', '/cabinet'),
            new ShellBreadcrumb('Доступ і дозволи'),
        ]);

        $permissions = [];
        foreach (TenantPermissions::all() as $permission) {
            $permissions[] = [
                'code' => $permission,
                'allowed' => $tenant->allows($permission),
            ];
        }

        return new Response(
            $this->twig->render('experience/account/access.html.twig', [
                'shell' => $shell,
                'userId' => $tenant->userId()->value(),
                'organizationId' => $tenant->organizationId()->value(),
                'role' => $tenant->role()->value(),
                'permissions' => $permissions,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
