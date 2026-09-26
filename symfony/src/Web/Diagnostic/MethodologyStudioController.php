<?php

declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Kernel\Module\ActiveModuleResolver;
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
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private DiagnosticMethodologyAccess $access,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->modules->isEnabled($tenant->organizationId()->value(), 'diagnostic')) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->allows(
            $tenant->organizationId()->value(),
            (int) $tenant->userId()->value(),
            DiagnosticMethodologyAccess::VIEW,
        )) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'diagnostics',
            activeItem: 'methodology-studio',
        );
        $shell = $this->shells->create($tenant, $context, 'Methodology Studio', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS', '/cos/control-center'),
            new ShellBreadcrumb('Diagnostics'),
            new ShellBreadcrumb('Methodology Studio'),
        ]);

        return new Response(
            $this->twig->render('experience/diagnostic/methodology_studio.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar'],
                    'normal',
                ),
                'csrfToken' => $this->csrf($request),
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

    private function csrf(Request $request): string
    {
        return $request->hasSession()
            ? (string) $request->getSession()->get('cos_csrf_token', '')
            : '';
    }
}
