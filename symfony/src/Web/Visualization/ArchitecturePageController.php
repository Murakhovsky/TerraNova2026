<?php
declare(strict_types=1);

namespace App\Web\Visualization;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
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

final readonly class ArchitecturePageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
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

        $variables = $this->baseVariables($tenant) + [
            'title' => 'COS Architecture Explorer',
            'pageAssetEntries' => ['cos-architecture-explorer'],
            'pageStatus' => null,
            'architectureDiagnostic' => null,
            'architectureHealth' => null,
        ];

        $stage = 'build_canonical_graph';
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
                $views[$name] = $this->projectionPayload(
                    $canonical,
                    $name,
                    new GraphView(layout: (string) ($description['layout'] ?? 'auto')),
                    $description,
                );
            }

            $names = $this->registry->names();
            $defaultView = $this->registry->has('system') ? 'system' : ($names[0] ?? '');
            $variables['architectureGraph'] = [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $defaultView,
            ];
            $variables['architectureViewDescriptions'] = $descriptions;
        } catch (Throwable $error) {
            $diagnostic = $this->failureDiagnostic($stage, $error);
            error_log(sprintf('[COS Visualization] Architecture Explorer index failed at %s: %s', $stage, $error->getMessage()));
            $variables['architectureGraph'] = [
                'views' => [],
                'summary' => $this->emptySummary(),
                'default_view' => '',
            ];
            $variables['architectureViewDescriptions'] = [];
            $variables['architectureDiagnostic'] = $diagnostic;
            $variables['pageStatus'] = $diagnostic['message'];
            return $this->html($request, 'visualization/architecture', $variables, 503);
        }

        return $this->html($request, 'visualization/architecture', $variables);
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
            $description = $this->registry->descriptions()[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
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
            error_log(sprintf('[COS Visualization] Architecture Explorer graph failed at %s: %s', $stage, $error->getMessage()));
            return new JsonResponse(['ok' => false, 'error' => $diagnostic['message'], 'diagnostic' => $diagnostic], 503);
        }
    }

    public function health(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            return new JsonResponse(['ok' => true, 'health' => $this->health->analyze($this->provider->provide())]);
        } catch (Throwable $error) {
            $diagnostic = $this->failureDiagnostic('analyze_canonical_graph', $error);
            return new JsonResponse(['ok' => false, 'error' => $diagnostic['message'], 'diagnostic' => $diagnostic], 503);
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

    /** @return array<string,mixed> */
    private function baseVariables(TenantContext $tenant): array
    {
        $role = $tenant->role()->value();
        return [
            'workspaceSection' => 'cos',
            'workspaceActive' => 'architecture',
            'workspaceActiveSection' => 'cos',
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ];
    }

    /** @param array<string,mixed> $variables */
    private function html(Request $request, string $view, array $variables, int $status = 200): Response
    {
        return new Response(
            $this->renderer->render($request, $view, $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
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

    /** @return array{nodes:int,edges:int,groups:int,node_types:array<string,int>,relations:array<string,int>,domains:array<int,mixed>} */
    private function emptySummary(): array
    {
        return ['nodes' => 0, 'edges' => 0, 'groups' => 0, 'node_types' => [], 'relations' => [], 'domains' => []];
    }
}
