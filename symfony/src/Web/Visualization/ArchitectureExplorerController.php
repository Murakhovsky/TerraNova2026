<?php
declare(strict_types=1);

namespace App\Web\Visualization;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use App\Web\WorkspacePageContext;
use Infrastructure\Visualization\Cytoscape\CytoscapeGraphMapper;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ArchitectureExplorerController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private WorkspacePageContext $page,
        private NavigationBuilder $navigation,
        private GraphProviderInterface $provider,
        private GraphProjectionRegistryInterface $registry,
        private GraphHealthAnalyzerInterface $health,
        private CytoscapeGraphMapper $mapper,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $variables = array_replace(
            $this->page->variables($request, $tenant, 'COS Architecture Explorer', 'architecture', ['cos-architecture-explorer'], 'cos'),
            [
                'pageStatus' => null,
                'architectureDiagnostic' => null,
                'architectureHealth' => null,
            ],
        );
        $stage = 'build_canonical_graph';
        $status = 200;

        try {
            $canonical = $this->provider->provide();
            $stage = 'analyze_canonical_graph';
            $variables['architectureHealth'] = $this->health->analyze($canonical);
            $stage = 'describe_projections';
            $descriptions = $this->registry->descriptions();

            $stage = 'map_canonical_graph';
            $canonicalPayload = $this->mapper->map($canonical);
            $views = [];
            foreach ($this->registry->names() as $name) {
                $stage = 'project_' . $name;
                $description = $descriptions[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
                $view = new GraphView(layout: (string) ($description['layout'] ?? 'auto'));
                $views[$name] = $this->projectionPayload($canonical, $name, $view, $description);
            }

            $names = $this->registry->names();
            $defaultView = $this->registry->has('system') ? 'system' : ($names[0] ?? '');
            $variables['architectureGraph'] = [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $defaultView,
            ];
            $variables['architectureViewDescriptions'] = $descriptions;
        } catch (Throwable $exception) {
            $this->reportFailure('index', $stage, $exception);
            $diagnostic = $this->failureDiagnostic($stage, $exception);
            $status = 503;
            $variables['architectureGraph'] = [
                'views' => [],
                'summary' => $this->emptySummary(),
                'default_view' => '',
            ];
            $variables['architectureViewDescriptions'] = [];
            $variables['architectureDiagnostic'] = $diagnostic;
            $variables['pageStatus'] = $diagnostic['message'];
        }

        return new Response(
            $this->renderer->render($request, 'visualization/architecture', $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function graph(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
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
            $description = $this->registry->descriptions()[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
            $view = new GraphView(
                focus: $focus !== '' ? $focus : null,
                depth: $depth,
                layout: (string) ($description['layout'] ?? 'auto'),
            );

            return new JsonResponse([
                'ok' => true,
                'graph' => $this->projectionPayload($canonical, $name, $view, $description),
            ]);
        } catch (Throwable $exception) {
            $this->reportFailure('graph', $stage, $exception);
            $diagnostic = $this->failureDiagnostic($stage, $exception);
            return new JsonResponse([
                'ok' => false,
                'error' => $diagnostic['message'],
                'diagnostic' => $diagnostic,
            ], 503);
        }
    }

    public function health(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $stage = 'build_canonical_graph';

        try {
            $graph = $this->provider->provide();
            $stage = 'analyze_canonical_graph';
            return new JsonResponse(['ok' => true, 'health' => $this->health->analyze($graph)]);
        } catch (Throwable $exception) {
            $this->reportFailure('health', $stage, $exception);
            $diagnostic = $this->failureDiagnostic($stage, $exception);
            return new JsonResponse([
                'ok' => false,
                'error' => $diagnostic['message'],
                'diagnostic' => $diagnostic,
            ], 503);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->page->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', 403);
        return $tenant;
    }

    /** @param array<string,mixed> $description @return array<string,mixed> */
    private function projectionPayload(Graph $canonical, string $name, GraphView $view, array $description): array
    {
        $payload = $this->mapper->map($this->registry->project($name, $canonical, $view));
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
    private function failureDiagnostic(string $stage, Throwable $exception): array
    {
        $detail = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'No exception message.';
        if (strlen($detail) > 320) $detail = substr($detail, 0, 317) . '...';

        return [
            'stage' => $stage,
            'exception' => $exception::class,
            'detail' => $detail,
            'message' => sprintf('Architecture Graph failure [%s] %s: %s', $stage, $exception::class, $detail),
        ];
    }

    private function reportFailure(string $surface, string $stage, Throwable $exception): void
    {
        error_log(sprintf(
            '[COS Visualization] Architecture Explorer %s failed at %s: %s: %s',
            $surface,
            $stage,
            $exception::class,
            $exception->getMessage(),
        ));
    }

    /** @return array{nodes:int,edges:int,groups:int,node_types:array<string,int>,relations:array<string,int>,domains:array<int,mixed>} */
    private function emptySummary(): array
    {
        return ['nodes' => 0, 'edges' => 0, 'groups' => 0, 'node_types' => [], 'relations' => [], 'domains' => []];
    }
}
