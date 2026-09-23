<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesDealWorkspaceQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Twig\Environment;

final readonly class SalesDealController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private WorkspaceCompositionResolver $workspaces,
        private PagePresentationFactory $pages,
        private SalesDealPresenter $presenter,
    ) {
    }

    public function index(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $dealId = (int) $id;
        if ($dealId <= 0) {
            throw new NotFoundHttpException('Deal not found.');
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'deals',
        );

        $shell = $this->shells->create($tenant, $context, 'Deal Workspace', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Deals', '/sales/deals'),
            new ShellBreadcrumb('Deal #' . $dealId),
        ]);

        $workspace = $this->workspaces->resolve(
            $tenant,
            $context,
            'sales.deal',
            new EntityRef('sales.deal', (string) $dealId),
        );

        try {
            $data = $this->queries->ask(
                new GetSalesDealWorkspaceQuery($tenant->organizationId(), $dealId),
            );

            if (!is_array($data)) {
                throw new NotFoundHttpException('Deal not found.');
            }

            $deal = $this->presenter->present($data, $dealId);

            return $this->render([
                'shell' => $shell,
                'workspace' => $workspace,
                'page' => $this->pages->create(
                    PageArchetype::EntityWorkspace,
                    $this->patterns(),
                    $deal->state(),
                ),
                'deal' => $deal,
                'csrfToken' => $this->csrf($request),
            ]);
        } catch (NotFoundHttpException $error) {
            throw $error;
        } catch (Throwable $error) {
            error_log('sales.deal.read_failed ' . $error->getMessage());
            $deal = $this->presenter->present(
                [],
                $dealId,
                'Deal Workspace тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'workspace' => $workspace,
                'page' => $this->pages->create(
                    PageArchetype::EntityWorkspace,
                    $this->patterns(),
                    'error',
                ),
                'deal' => $deal,
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
            'WorkspaceHeader',
            'EntityHeader',
            'KpiStrip',
            'ContextPanel',
            'ActionBar',
            'Timeline',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/deal_workspace.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
