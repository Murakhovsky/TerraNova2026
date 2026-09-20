<?php
declare(strict_types=1);

namespace App\Web\Visualization;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
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
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private GraphProviderInterface $provider,
        private GraphProjectionRegistryInterface $projections,
        private object $mapper,
        private GraphHealthAnalyzerInterface $health,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $stage = 'build_canonical_graph';
        $diagnostic = null;
        $pageStatus = null;
        $health = null;

        try {
            $canonical = $this->provider->provide();
            $stage = 'analyze_canonical_graph';
            $health = $this->health->analyze($canonical);
            $stage = 'describe_projections';
            $descriptions = $this->projections->descriptions();
            $stage = 'map_canonical_graph';
            $canonicalPayload = $this->mapper->map($canonical);

            $views = [];
            foreach ($this->projections->names() as $name) {
                $stage = 'project_' . $name;
                $description = $descriptions[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
                $view = new GraphView(layout: (string) ($description['layout'] ?? 'auto'));
                $views[$name] = $this->projectionPayload($canonical, $name, $view, $description);
            }

            $names = $this->projections->names();
            $defaultView = $this->projections->has('system') ? 'system' : ($names[0] ?? '');
            $graph = [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $defaultView,
            ];
        } catch (Throwable $exception) {
            $this->reportFailure('index', $stage, $exception);
            $diagnostic = $this->failureDiagnostic($stage, $exception);
            $pageStatus = $diagnostic['message'];
            $graph = [
                'views' => [],
                'summary' => $this->emptySummary(),
                'default_view' => '',
            ];
            $descriptions = [];
        }

        $role = $tenant->role()->value();
        $html = $this->renderer->render($request, 'visualization/architecture', [
            'title' => 'COS Architecture Explorer',
            'metaTitle' => 'COS Architecture Explorer | Terra Nova COS',
            'workspaceSection' => 'cos',
            'workspaceActive' => 'architecture',
            'workspaceActiveSection' => 'cos',
            'pageAssetEntries' => ['cos-architecture-explorer'],
            'pageStatus' => $pageStatus,
            'architectureDiagnostic' => $diagnostic,
            'architectureHealth' => $health,
            'architectureGraph' => $graph,
            'architectureViewDescriptions' => $descriptions,
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ]);

        return new Response(
            $html,
            $diagnostic === null ? 200 : 503,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
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
            if (!$this->projections->has($name)) {
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

            $description = $this->projections->descriptions()[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
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
        if ($tenant instanceof Response) {
            return $tenant;
        }

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
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', 403);
        }

        return $tenant;
    }

    /** @param array<string,mixed> $description @return array<string,mixed> */
    private function projectionPayload(Graph $canonical, string $name, GraphView $view, array $description): array
    {
        $payload = $this->mapper->map($this->projections->project($name, $canonical, $view));
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
        if (strlen($detail) > 320) {
            $detail = substr($detail, 0, 317) . '...';
        }

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
        return [
            'nodes' => 0,
            'edges' => 0,
            'groups' => 0,
            'node_types' => [],
            'relations' => [],
            'domains' => [],
        ];
    }
}
