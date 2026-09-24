<?php

declare(strict_types=1);

namespace App\Web\Identity;

use App\Security\SessionCsrfValidator;
use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserAdministrationMutationController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private AdministrationServiceInterface $administration,
    ) {
    }

    public function create(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $result = $this->administration->createUser($request->request->all());

        return $this->redirect((string) ($result['message'] ?? 'Користувача оброблено.'));
    }

    public function update(Request $request, string $id): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $result = $this->administration->updateUser(
            (int) $id,
            $request->request->all(),
            [
                'id' => (int) $tenant->userId()->value(),
                'role' => $tenant->role()->value(),
            ],
        );

        return $this->redirect((string) ($result['message'] ?? 'Користувача оновлено.'));
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isAdmin()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    private function redirect(string $message): RedirectResponse
    {
        return new RedirectResponse('/admin/users?status_message=' . rawurlencode($message));
    }
}
