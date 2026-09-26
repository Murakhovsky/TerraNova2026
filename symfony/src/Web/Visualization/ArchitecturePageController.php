<?php

declare(strict_types=1);

namespace App\Web\Visualization;

use App\Application\Visualization\Query\GetArchitectureHealthQuery;
use App\Application\Visualization\Query\GetArchitectureOverviewQuery;
use App\Application\Visualization\Query\GetArchitectureProjectionQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use InvalidArgumentException;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class ArchitecturePageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private QueryBusInterface $queries,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ArchitectureOverviewPresenter $presenter,
    ) {
    }

    public function index(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'cos',
            activeItem: 'architecture',
        );
        $shell = $this->shells->create($tenant, $context, 'COS Architecture Explorer', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS', '/cos/control-center'),
            new ShellBreadcrumb('Architecture'),
        ]);

        try {
            $data = $this->queries->ask(new GetArchitectureOverviewQuery());
            $architecture = $this->presenter->present(is_array($data) ? $data : []);

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    $architecture->state(),
                ),
                'architecture' => $architecture,
            ]);
        } catch (Throwable $error) {
            error_log('[COS Visualization] Architecture Explorer index failed: ' . $error->getMessage());
            $architecture = $this->presenter->present(
                [],
                'Architecture Explorer тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    'error',
                ),
                'architecture' => $architecture,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function graph(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $view = trim((string) $request->query->get('view', 'domain')) ?: 'domain';
        $focus = trim((string) $request->query->get('focus', ''));
        $depthValue = trim((string) $request->query->get('depth', ''));
        $depth = null;

        if ($depthValue === 'all') {
            $focus = '';
        } elseif ($depthValue !== '') {
            if (!ctype_digit($depthValue)) {
                return new JsonResponse(['ok' => false, 'error' => 'Depth must be a non-negative integer or all.'], Response::HTTP_BAD_REQUEST);
            }
            $depth = (int) $depthValue;
            if ($depth > 6) {
                return new JsonResponse(['ok' => false, 'error' => 'Depth cannot exceed 6 hops.'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $payload = $this->queries->ask(new GetArchitectureProjectionQuery(
                $view,
                $focus !== '' ? $focus : null,
                $depth,
            ));

            return new JsonResponse(['ok' => true, 'graph' => is_array($payload) ? $payload : []]);
        } catch (InvalidArgumentException $error) {
            return new JsonResponse(['ok' => false, 'error' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (Throwable $error) {
            $diagnostic = $this->failureDiagnostic('project_' . $view, $error);
            error_log(sprintf('[COS Visualization] Architecture Explorer graph failed: %s', $error->getMessage()));

            return new JsonResponse([
                'ok' => false,
                'error' => $diagnostic['message'],
                'diagnostic' => $diagnostic,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function health(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            $health = $this->queries->ask(new GetArchitectureHealthQuery());

            return new JsonResponse(['ok' => true, 'health' => is_array($health) ? $health : []]);
        } catch (Throwable $error) {
            $diagnostic = $this->failureDiagnostic('analyze_canonical_graph', $error);

            return new JsonResponse([
                'ok' => false,
                'error' => $diagnostic['message'],
                'diagnostic' => $diagnostic,
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

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'Toolbar',
            'KpiStrip',
            'ContextPanel',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/system/architecture.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    /** @return array{stage:string,exception:string,detail:string,message:string} */
    private function failureDiagnostic(string $stage, Throwable $error): array
    {
        $detail = preg_replace('/\s+/', ' ', trim($error->getMessage())) ?: 'No exception message.';
        if (strlen($detail) > 320) {
            $detail = substr($detail, 0, 317) . '...';
        }

        return [
            'stage' => $stage,
            'exception' => $error::class,
            'detail' => $detail,
            'message' => sprintf('Architecture Graph failure [%s] %s: %s', $stage, $error::class, $detail),
        ];
    }
}
