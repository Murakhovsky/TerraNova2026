<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesTodayQuery;
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

final readonly class SalesTodayController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesTodayPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $scope = strtolower(trim((string) $request->query->get('scope', 'mine')));
        $scope = $scope === 'team' ? 'team' : 'mine';
        $ownerId = $scope === 'team' ? 0 : (int) $tenant->userId()->value();

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'today',
        );
        $shell = $this->shells->create($tenant, $context, 'Sales Today', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Today'),
        ]);

        try {
            $data = $this->queries->ask(
                new GetSalesTodayQuery($tenant->organizationId(), $ownerId),
            );
            $today = $this->presenter->present(
                is_array($data) ? $data : [],
                $scope,
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::OperationalQueue,
                    $this->patterns(),
                    $today->state(),
                ),
                'today' => $today,
                'csrfToken' => $this->csrf($request),
            ]);
        } catch (Throwable $error) {
            error_log('sales.today.read_failed ' . $error->getMessage());
            $today = $this->presenter->present(
                [],
                $scope,
                'Sales Today тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::OperationalQueue,
                    $this->patterns(),
                    'error',
                ),
                'today' => $today,
                'csrfToken' => $this->csrf($request),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
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
            'EntityList',
            'ActionBar',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/today.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
