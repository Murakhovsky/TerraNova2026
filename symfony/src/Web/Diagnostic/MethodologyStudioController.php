<?php

declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Application\Diagnostic\Methodology\GetMethodologyStudioAccessQuery;
use App\Security\SessionCsrfValidator;
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
use Twig\Environment;

final readonly class MethodologyStudioController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private QueryBusInterface $queries,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $access = $this->queries->ask(new GetMethodologyStudioAccessQuery(
            $tenant->organizationId(),
            (int) $tenant->userId()->value(),
        ));
        $access = is_array($access) ? $access : [];

        if (!($access['enabled'] ?? false)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        if (!($access['allowed'] ?? false)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'cos',
            activeItem: 'diagnostics',
        );
        $shell = $this->shells->create($tenant, $context, 'Diagnostic Methodology Studio', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS', '/cos/control-center'),
            new ShellBreadcrumb('Diagnostics'),
            new ShellBreadcrumb('Methodology Studio'),
        ]);

        return new Response(
            $this->twig->render('experience/system/methodology_studio.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar'],
                    'normal',
                ),
                'csrfToken' => $this->csrf->token($request),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    private function authenticated(): TenantContext|Response
    {
        return $this->tenants->current() ?? new RedirectResponse('/auth/login');
    }
}
