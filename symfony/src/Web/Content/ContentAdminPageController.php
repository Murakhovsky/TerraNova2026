<?php

declare(strict_types=1);

namespace App\Web\Content;

use App\Application\Content\Command\SaveContentCommand;
use App\Application\Content\Query\GetContentAdministrationQuery;
use App\Application\Content\Query\GetContentEditorQuery;
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

final readonly class ContentAdminPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ContentAdminPresenter $presenter,
    ) {
    }

    public function manage(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $shell = $this->shell($tenant, 'Content Administration', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Administration'),
            new ShellBreadcrumb('Content'),
        ]);

        try {
            $data = $this->queries->ask(new GetContentAdministrationQuery($request->query->all()));
            $content = $this->presenter->manage(is_array($data) ? $data : []);

            return new Response(
                $this->twig->render('experience/content/manage.html.twig', [
                    'shell' => $shell,
                    'page' => $this->pages->create(
                        PageArchetype::SystemControlSurface,
                        ['PageHeader', 'Toolbar', 'KpiStrip', 'FilterBar', 'DataGrid', 'EntityList', 'EmptyState', 'ErrorState'],
                        $content->state(),
                    ),
                    'content' => $content,
                ]),
                Response::HTTP_OK,
                $this->headers(),
            );
        } catch (Throwable $error) {
            error_log(sprintf('content.admin.manage [%s] %s', $error::class, $error->getMessage()));
            $content = $this->presenter->manage([], 'Контент тимчасово недоступний. Деталі записано в лог.');

            return new Response(
                $this->twig->render('experience/content/manage.html.twig', [
                    'shell' => $shell,
                    'page' => $this->pages->create(
                        PageArchetype::SystemControlSurface,
                        ['PageHeader', 'Toolbar', 'KpiStrip', 'FilterBar', 'DataGrid', 'EntityList', 'EmptyState', 'ErrorState'],
                        'error',
                    ),
                    'content' => $content,
                ]),
                Response::HTTP_SERVICE_UNAVAILABLE,
                $this->headers(),
            );
        }
    }

    public function edit(Request $request, string $id = '0'): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $contentId = ctype_digit($id) ? (int) $id : 0;
        $shell = $this->shell($tenant, $contentId > 0 ? 'Edit Content' : 'New Content', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Administration'),
            new ShellBreadcrumb('Content', '/admin/content'),
            new ShellBreadcrumb($contentId > 0 ? 'Edit' : 'Create'),
        ]);

        try {
            $data = $this->queries->ask(new GetContentEditorQuery(
                $contentId,
                (string) $request->query->get('type', 'blog_post'),
            ));
            $editor = $this->presenter->editor(
                is_array($data) ? $data : [],
                (string) $request->query->get('status_message', ''),
            );
            $status = $editor->notFound ? Response::HTTP_NOT_FOUND : Response::HTTP_OK;

            return new Response(
                $this->twig->render('experience/content/edit.html.twig', [
                    'shell' => $shell,
                    'page' => $this->pages->create(
                        PageArchetype::FormEditor,
                        ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                        $editor->notFound ? 'error' : 'normal',
                    ),
                    'editor' => $editor,
                    'csrfToken' => $this->csrf->token($request),
                ]),
                $status,
                $this->headers(),
            );
        } catch (Throwable $error) {
            error_log(sprintf('content.admin.edit [%s] %s', $error::class, $error->getMessage()));

            return new Response(
                $this->twig->render('experience/content/edit.html.twig', [
                    'shell' => $shell,
                    'page' => $this->pages->create(
                        PageArchetype::FormEditor,
                        ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                        'error',
                    ),
                    'editor' => $this->presenter->editor(
                        ['item' => null, 'revisions' => [], 'not_found' => true],
                        'Контент тимчасово недоступний.',
                    ),
                    'csrfToken' => $this->csrf->token($request),
                ]),
                Response::HTTP_SERVICE_UNAVAILABLE,
                $this->headers(),
            );
        }
    }

    public function save(Request $request, string $id = '0'): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);
        }

        $input = $request->request->all();
        $routeId = ctype_digit($id) ? (int) $id : 0;
        $input['id'] = $routeId > 0 ? $routeId : (int) ($input['id'] ?? 0);

        $result = $this->commands->dispatch(new SaveContentCommand(
            $input,
            [
                'id' => (int) $tenant->userId()->value(),
                'role' => $tenant->role()->value(),
                'organization_id' => $tenant->organizationId()->value(),
            ],
        ));
        $result = is_array($result) ? $result : [];

        $target = !empty($result['id'])
            ? '/admin/content/edit/' . (int) $result['id']
            : '/admin/content/edit';

        return new RedirectResponse(
            $target . '?status_message=' . rawurlencode((string) ($result['message'] ?? '')),
            Response::HTTP_SEE_OTHER,
        );
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @param list<ShellBreadcrumb> $breadcrumbs */
    private function shell(TenantContext $tenant, string $title, array $breadcrumbs): object
    {
        return $this->shells->create(
            $tenant,
            new WebExtensionContext(
                organizationId: $tenant->organizationId()->value(),
                role: $tenant->role()->value(),
                surface: 'system',
                activeSection: 'administration',
                activeItem: 'content',
            ),
            $title,
            $breadcrumbs,
        );
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
