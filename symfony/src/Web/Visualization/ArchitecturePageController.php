<?php

declare(strict_types=1);

namespace App\Web\Visualization;

use App\Application\Visualization\Query\GetArchitectureExplorerQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphMapperInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
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
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ArchitectureExplorerPresenter $presenter,
        private GraphProviderInterface $provider,
        private GraphProjectionRegistryInterface $registry,
        private GraphHealthAnalyzerInterface $health,
        private GraphMapperInterface $mapper,
    ) {
    }

    public function index(Request $request): Response
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
        $shell = $this->shells->create($tenant, $context, 'Architecture Explorer', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS', '/cos/control-center'),
            new ShellBreadcrumb('Architecture'),
        ]);

        try {
            $data = $this->queries->ask(new GetArchitectureExplorerQuery());
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
            $diagnostic = $this->failureDiagnostic('architecture_explorer_query', $error);
            error_log(sprintf(
                '[COS Visualization] Architecture Explorer index failed: %s',
                $error->getMessage(),
            ));
            $architecture = $this->presenter->present(
                [],
                $diagnostic,
                $diagnostic['message'],
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

        $stage = 'build_canonical_graph';
        try {
            $name = trim((string) $request->query->get('view', 'domain'));
            if (!$this->registry->has($name)) {
                return new JsonResponse(['ok' => false, 'error' => 'Unknown architecture projection.'], 404);
            }

            $canonical = $this->provider->provide();
            $focus = trim((string) $request->query->get('focus', ''));
            $depthValue = trim((string) $request->query->get('depth', ''));
            $depth = null;

            if ($depthValue === 'all') {
                $focus = '';
            } elseif ($depthValue !== '') {
                if (!ctype_digit($depthValue)) {
                    return new JsonResponse(['ok' => false, 'error' => 'Depth must be a non-negative integer or all.'], 400);
                }
                $depth = (int) $depthValue;
                if ($depth > 6) {
                    return new JsonResponse(['ok' => false, 'error' => 'Depth cannot exceed 6 hops.'], 400);
                }
            }

            if ($focus !== '' && !$canonical->hasNode($focus)) {
                return new JsonResponse(['ok' => false, 'error' => 'Architecture focus node was not found.'], 404);
            }

            $stage = 'project_' . $name;
            $description = $this->registry->descriptions()[$name] ?? [
                'label' => $name,
                'layout' => 'auto',
                'default_depth' => null,
            ];
            $payload = $this->projectionPayload(
                $canonical,
                $name,
                new GraphView(
                    focus: $focus !== '' ? $focus : null,
                    depth: $depth,
                    layout: (string) ($description['layout'] ?? 'auto'),
                ),
                $description,
            );

            return new JsonResponse(['ok' => true, 'graph' => $payload]);
        } catch (Throwable $error) {
            $diagnostic = $this->failureDiagnostic($stage, $error);
            error_log(sprintf(
                '[COS Visualization] Architecture Explorer graph failed at %s: %s',
                $stage,
                $error->getMessage(),
            ));

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
            return new JsonResponse([
                'ok' => true,
                'health' => $this->health->analyze($this->provider->provide()),
            ]);
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
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/visualization/architecture.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    /** @param array<string,mixed> $description @return array<string,mixed> */
    private function projectionPayload(
        Graph $canonical,
        string $name,
        GraphView $view,
        array $description,
    ): array {
        $payload = $this->mapper->map(
            $this->registry->project($name, $canonical, $view),
        );
        $payload['view'] = [
            'name' => $name,
            'label' => (string) ($description['label'] ?? $name),
            'layout' => (string) ($description['layout'] ?? $view->layout),
            'focus' => $view->focus,
            'depth' => $view->depth,
            'default_depth' => $description['default_depth'] ?? null,
        ];

        return $payload;
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
            'message' => sprintf(
                'Architecture Graph failure [%s] %s: %s',
                $stage,
                $error::class,
                $detail,
            ),
        ];
    }
}
