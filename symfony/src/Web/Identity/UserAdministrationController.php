<?php

declare(strict_types=1);

namespace App\Web\Identity;

use App\Application\Identity\Query\GetUserAdministrationQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class UserAdministrationController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private UserAdministrationPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $shell = $this->shells->create(
            $tenant,
            new WebExtensionContext(
                organizationId: $tenant->organizationId()->value(),
                role: $tenant->role()->value(),
                surface: 'system',
                activeSection: 'administration',
                activeItem: 'users',
            ),
            'Користувачі та ролі',
            [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Administration', '/admin/content'),
                new ShellBreadcrumb('Users'),
            ],
        );
        $actionStatus = (string) $request->query->get('status_message', '');

        try {
            $data = $this->queries->ask(new GetUserAdministrationQuery($request->query->all()));
            $users = $this->presenter->present(
                is_array($data) ? $data : [],
                $actionStatus,
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    $users->state(),
                ),
                'users' => $users,
                'csrfToken' => $this->csrf($request),
            ]);
        } catch (Throwable $error) {
            error_log('workspace.users.read_failed ' . $error->getMessage());
            $users = $this->presenter->present(
                [],
                '',
                'Керування користувачами тимчасово недоступне.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    'error',
                ),
                'users' => $users,
                'csrfToken' => $this->csrf($request),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
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

    private function csrf(Request $request): string
    {
        return $request->hasSession()
            ? (string) $request->getSession()->get('cos_csrf_token', '')
            : '';
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'Toolbar',
            'KpiStrip',
            'FilterBar',
            'DataGrid',
            'EntityList',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/admin/users.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
