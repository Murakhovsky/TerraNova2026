<?php

declare(strict_types=1);

namespace App\Web\Administration;

use App\Application\Administration\Command\CreateAdministrationUserCommand;
use App\Application\Administration\Command\UpdateAdministrationUserCommand;
use App\Application\Administration\Query\GetAdministrationUsersQuery;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class AdministrationUsersController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private AdministrationUsersPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'administration',
            activeItem: 'users',
        );
        $shell = $this->shells->create($tenant, $context, 'Users Administration', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Administration'),
            new ShellBreadcrumb('Users'),
        ]);
        $actionStatus = trim((string) $request->query->get('status_message', ''));

        try {
            $data = $this->queries->ask(new GetAdministrationUsersQuery($request->query->all()));
            $users = $this->presenter->present(is_array($data) ? $data : [], $actionStatus);

            return $this->render($shell, $users, $users->state(), $this->csrf->token($request));
        } catch (Throwable $error) {
            error_log('administration.users.read_failed ' . $error->getMessage());
            $users = $this->presenter->present(
                [],
                $actionStatus,
                'Керування користувачами тимчасово недоступне.',
            );

            return $this->render(
                $shell,
                $users,
                'error',
                $this->csrf->token($request),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    public function create(Request $request): Response
    {
        $tenant = $this->adminMutation($request);
        if ($tenant instanceof Response) return $tenant;

        try {
            $result = $this->commands->dispatch(new CreateAdministrationUserCommand($request->request->all()));
            return $this->redirect($result, 'Користувача оброблено.');
        } catch (Throwable $error) {
            return $this->redirect(['message' => 'Помилка: ' . $error->getMessage()], 'Не вдалося створити користувача.');
        }
    }

    public function update(Request $request, string $id): Response
    {
        $tenant = $this->adminMutation($request);
        if ($tenant instanceof Response) return $tenant;

        try {
            $result = $this->commands->dispatch(new UpdateAdministrationUserCommand(
                (int) $id,
                $request->request->all(),
                [
                    'id' => (int) $tenant->userId()->value(),
                    'role' => $tenant->role()->value(),
                ],
            ));

            return $this->redirect($result, 'Користувача оновлено.');
        } catch (Throwable $error) {
            return $this->redirect(['message' => 'Помилка: ' . $error->getMessage()], 'Не вдалося оновити користувача.');
        }
    }

    private function adminMutation(Request $request): TenantContext|Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isAdmin()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function redirect(mixed $result, string $fallback): RedirectResponse
    {
        $message = is_array($result) ? (string) ($result['message'] ?? $fallback) : $fallback;
        return new RedirectResponse('/admin/users?status_message=' . rawurlencode($message));
    }

    private function render(object $shell, object $users, string $state, string $csrfToken, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/administration/users.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar', 'KpiStrip', 'FilterBar', 'DataGrid', 'EntityList', 'ActionBar', 'EmptyState', 'ErrorState'],
                    $state,
                ),
                'users' => $users,
                'csrfToken' => $csrfToken,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
