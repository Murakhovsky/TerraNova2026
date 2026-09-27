<?php

declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Application\Diagnostic\Report\GetDiagnosticReportQuery;
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

final readonly class DiagnosticPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private QueryBusInterface $queries,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private DiagnosticReportPresenter $presenter,
    ) {
    }

    public function report(Request $request, string $session): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            $result = $this->queries->ask(new GetDiagnosticReportQuery(
                $tenant->organizationId(),
                (int) $tenant->userId()->value(),
                $session,
            ));
            $result = is_array($result) ? $result : [];

            if (!($result['enabled'] ?? false)) {
                return new Response('Not Found', Response::HTTP_NOT_FOUND);
            }
            if (!($result['allowed'] ?? false)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }

            $envelope = is_array($result['envelope'] ?? null) ? $result['envelope'] : [];
            $report = $this->presenter->present($session, $envelope);
        } catch (Throwable $error) {
            error_log('diagnostic.report.read_failed ' . $error->getMessage());

            return new Response('Diagnostic report was not found.', Response::HTTP_NOT_FOUND);
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'cos',
            activeItem: 'diagnostics',
        );
        $shell = $this->shells->create($tenant, $context, 'Diagnostic Report', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS', '/cos/control-center'),
            new ShellBreadcrumb('Diagnostics'),
            new ShellBreadcrumb($report->title),
        ]);

        return new Response(
            $this->twig->render('experience/diagnostic/report.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'KpiStrip', 'EntityList'],
                    'normal',
                ),
                'report' => $report,
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
